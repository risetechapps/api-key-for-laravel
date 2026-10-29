<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use RiseTechApps\ApiKey\Http\Controllers\Dashboard\Cards\CardController;
use RiseTechApps\ApiKey\Jobs\ProcessPlanRenewalJob;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\AuditLog\AuditLog;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\PendingPayment\PendingPayment;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Notifications\OverageChargeNotification;
use RiseTechApps\ApiKey\Notifications\OverageStartedNotification;
use RiseTechApps\ApiKey\Notifications\RenewalFailedNotification;
use RiseTechApps\ApiKey\Notifications\RenewalPaymentRequiredNotification;
use RiseTechApps\ApiKey\Services\MpCustomerService;
use RiseTechApps\ApiKey\Services\PaymentOutcomeService;
use RiseTechApps\ApiKey\Tests\Support\FakeMercadoPagoHttpClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    $this->gateway = new FakeMercadoPagoHttpClient;
    MercadoPagoConfig::setHttpClient($this->gateway);
    config(['api-key.mercadopago.access_token' => 'TEST-not-a-real-token']);
    Http::fake(['*/v1/card_tokens' => Http::response(['id' => 'card-token-1'])]);

    $this->user = Authentication::factory()->create();
    $this->plan = Plan::factory()->create(['price' => 50.00, 'request_limit' => 100]);
});

afterEach(function () {
    MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient);
});

function savedCard(): UserCard
{
    return UserCard::create([
        'authentication_id' => test()->user->getKey(),
        'holder_name' => 'ANA',
        'last_four' => '4242',
        'brand' => 'visa',
        'expiry_month' => 12,
        'expiry_year' => 2030,
        'mp_customer_id' => 'cust-1',
        'mp_card_id' => 'card-1',
        'is_default' => true,
    ]);
}

function subscription(array $attributes = []): UserPlan
{
    return UserPlan::factory()->create([
        'authentication_id' => test()->user->getKey(),
        'plan_id' => test()->plan->getKey(),
        'active' => true,
        'start_date' => now()->subDays(29),
        'end_date' => now()->endOfDay(),
        ...$attributes,
    ]);
}

function runRenewal(UserPlan $userPlan): void
{
    (new ProcessPlanRenewalJob($userPlan->getKey()))->handle(app(MpCustomerService::class));
}

describe('Renewal retries', function () {
    it('schedules the next attempt and warns the subscriber when the card is declined', function () {
        savedCard();
        $userPlan = subscription();
        $this->gateway->pushResponse(['id' => 9001, 'status' => 'rejected', 'status_detail' => 'cc_rejected_insufficient_amount']);

        runRenewal($userPlan);

        $userPlan->refresh();
        expect($userPlan->renewal_attempts)->toBe(1)
            ->and($userPlan->next_renewal_attempt_at->toDateString())->toBe($userPlan->end_date->copy()->addDay()->toDateString())
            ->and($userPlan->renewal_failed_at)->toBeNull();

        Notification::assertSentTo($this->user, RenewalFailedNotification::class, fn ($n) => $n->attempt === 1 && $n->nextAttemptAt !== null);
    });

    it('uses a new idempotency key on each attempt', function () {
        savedCard();
        $userPlan = subscription();
        $this->gateway->pushResponse(['id' => 1, 'status' => 'rejected']);
        $this->gateway->pushResponse(['id' => 2, 'status' => 'rejected']);

        runRenewal($userPlan);
        runRenewal($userPlan->fresh());

        expect($this->gateway->header('X-Idempotency-Key', 0))
            ->not->toBe($this->gateway->header('X-Idempotency-Key', 1));
    });

    it('gives up after the last configured attempt', function () {
        config(['api-key.billing.retry_days' => [1]]);
        savedCard();
        $userPlan = subscription(['renewal_attempts' => 1, 'next_renewal_attempt_at' => now()]);
        $this->gateway->pushResponse(['id' => 3, 'status' => 'rejected']);

        runRenewal($userPlan);

        $userPlan->refresh();
        expect($userPlan->renewal_failed_at)->not->toBeNull()
            ->and($userPlan->next_renewal_attempt_at)->toBeNull();

        Notification::assertSentTo($this->user, RenewalFailedNotification::class, fn ($n) => $n->nextAttemptAt === null);
    });

    it('renews on a later attempt', function () {
        savedCard();
        $userPlan = subscription(['renewal_attempts' => 1, 'next_renewal_attempt_at' => now()]);
        $this->gateway->pushResponse(['id' => 4, 'status' => 'approved']);

        runRenewal($userPlan);

        expect($userPlan->fresh()->active)->toBeFalse()
            ->and(UserPlan::where('payment_id', '4')->where('active', true)->exists())->toBeTrue();
    });

    it('sends the subscriber to the dashboard when the account requires CVV, without retries', function () {
        savedCard();
        $userPlan = subscription();
        $this->gateway->pushFailure([
            'message' => "security_code_id can't be null",
            'error' => 'bad_request',
            'status' => 400,
            'cause' => [['code' => 3031, 'description' => "security_code_id can't be null"]],
        ]);

        runRenewal($userPlan);

        $userPlan->refresh();
        expect($userPlan->renewal_requires_payment)->toBe('cvv_required')
            ->and($userPlan->renewal_attempts)->toBe(0)
            ->and($userPlan->next_renewal_attempt_at)->toBeNull()
            ->and($userPlan->renewal_failed_at)->not->toBeNull();

        Notification::assertSentTo($this->user, RenewalPaymentRequiredNotification::class, fn ($n) => $n->reason === 'cvv_required');
        Notification::assertNotSentTo($this->user, RenewalFailedNotification::class);
    });

    it('asks a subscriber without card to renew in the dashboard', function () {
        $userPlan = subscription();

        runRenewal($userPlan);

        expect($userPlan->fresh()->renewal_requires_payment)->toBe('no_card');
        Notification::assertSentTo($this->user, RenewalPaymentRequiredNotification::class, fn ($n) => $n->reason === 'no_card');
    });

    it('picks up due retries in the billing command', function () {
        Queue::fake();
        subscription(['end_date' => now()->subDay(), 'renewal_attempts' => 1, 'next_renewal_attempt_at' => now()->subMinute()]);

        $this->artisan('api-key:billing-process-renewals')->assertSuccessful();

        Queue::assertPushed(ProcessPlanRenewalJob::class, 1);
    });

    it('does not expire a subscription that still has a retry scheduled', function () {
        $userPlan = subscription([
            'end_date' => now()->subDays(10),
            'renewal_attempts' => 2,
            'next_renewal_attempt_at' => now()->addDay(),
        ]);

        $this->artisan('api-key:check-expired-plans')->assertSuccessful();

        expect($userPlan->fresh()->active)->toBeTrue();
    });

    it('retries right away when a new card is saved', function () {
        Queue::fake();
        $userPlan = subscription(['renewal_attempts' => 1, 'next_renewal_attempt_at' => now()->addDays(2)]);

        (new ReflectionMethod(CardController::class, 'retryFailedRenewal'))
            ->invoke(app(CardController::class), $this->user, savedCard());

        Queue::assertPushed(ProcessPlanRenewalJob::class, fn ($job) => $job->userPlanId === $userPlan->getKey());
        expect($userPlan->fresh()->payment_method)->toBe('card');
    });
});

describe('PIX', function () {
    beforeEach(fn () => config(['api-key.mercadopago.pix.enabled' => true]));

    it('returns the QR code and records the pending payment', function () {
        Sanctum::actingAs($this->user);
        $this->gateway->pushResponse([
            'id' => 7001,
            'status' => 'pending',
            'point_of_interaction' => ['transaction_data' => [
                'qr_code' => '000201...',
                'qr_code_base64' => 'iVBOR...',
                'ticket_url' => 'https://mp.test/pix/7001',
            ]],
        ]);

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            'payment_method' => 'pix',
        ])->assertOk()
            ->assertJsonPath('data.status', 'pix')
            ->assertJsonPath('data.qr_code', '000201...')
            ->assertJsonPath('data.payment_id', '7001');

        expect($this->gateway->payload()['payment_method_id'])->toBe('pix')
            ->and($this->gateway->payload())->toHaveKey('date_of_expiration')
            ->and(PendingPayment::where('payment_id', '7001')->exists())->toBeTrue();

        $this->getJson('/api/v1/dashboard/checkout/payments/7001')->assertJsonPath('data.status', 'pending');
    });

    it('subscribes when the webhook confirms the PIX', function () {
        PendingPayment::create([
            'authentication_id' => $this->user->getKey(),
            'plan_id' => $this->plan->getKey(),
            'payment_id' => '7002',
            'amount' => 50,
            'status' => 'pending',
        ]);

        app(PaymentOutcomeService::class)->apply((object) [
            'id' => 7002,
            'status' => 'approved',
            'payment_method_id' => 'pix',
            'transaction_amount' => 50,
            'external_reference' => $this->user->getKey().'|'.$this->plan->getKey(),
        ]);

        $userPlan = UserPlan::where('payment_id', '7002')->sole();
        expect($userPlan->payment_method)->toBe('pix');

        Sanctum::actingAs($this->user);
        $this->getJson('/api/v1/dashboard/checkout/payments/7002')->assertJsonPath('data.status', 'approved');
    });

    it('is refused when disabled', function () {
        config(['api-key.mercadopago.pix.enabled' => false]);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            'payment_method' => 'pix',
        ])->assertStatus(422);
    });

    it('asks a PIX subscriber to renew in the dashboard, without charging or emailing a QR', function () {
        $userPlan = subscription(['payment_method' => 'pix']);

        runRenewal($userPlan);

        $userPlan->refresh();
        expect($this->gateway->requests)->toBe([])
            ->and($userPlan->renewal_requires_payment)->toBe('pix')
            ->and($userPlan->next_renewal_attempt_at)->toBeNull()
            ->and(PendingPayment::count())->toBe(0);

        Notification::assertSentTo($this->user, RenewalPaymentRequiredNotification::class, fn ($n) => $n->reason === 'pix');
    });
});

describe('Trial', function () {
    beforeEach(fn () => $this->plan->update(['trial_days' => 7]));

    it('starts a trial for a new customer with a card, without charging', function () {
        savedCard();
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/dashboard/checkout/process', ['plan_id' => $this->plan->getKey()])
            ->assertOk()
            ->assertJsonPath('data.status', 'trial');

        $userPlan = $this->user->fresh()->activePlan;

        expect($userPlan->is_trial)->toBeTrue()
            ->and((int) round(now()->diffInDays($userPlan->end_date)))->toBe(7)
            ->and($this->user->fresh()->trial_used_at)->not->toBeNull()
            ->and($this->gateway->requests)->toBe([]);
    });

    it('is offered only once per account', function () {
        savedCard();
        $this->user->forceFill(['trial_used_at' => now()->subYear()])->save();
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/dashboard/checkout/process', ['plan_id' => $this->plan->getKey()]);

        expect($response->json('data.status'))->not->toBe('trial');
    });

    it('is not offered to someone who already paid', function () {
        savedCard();
        subscription(['payment_id' => '1', 'active' => false, 'end_date' => now()->subMonth()]);
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/dashboard/checkout/process', ['plan_id' => $this->plan->getKey()]);

        expect($response->json('data.status'))->not->toBe('trial');
    });
});

describe('Overage', function () {
    beforeEach(function () {
        $this->plan->update(['overage_price' => 0.10, 'overage_max_requests' => 50]);
        // Desligado por padrão: estes casos são de quem ligou.
        $this->user->forceFill(['overage_enabled' => true])->save();
    });

    it('blocks at the plan limit while the subscriber has not turned overage on', function () {
        $this->user->forceFill(['overage_enabled' => false])->save();
        subscription(['end_date' => now()->addDays(10), 'requests_used' => 100]);
        $key = ApiKey::factory()->create(['authentication_id' => $this->user->getKey(), 'active' => true]);

        $this->withHeaders(['X-API-KEY' => $key->plainKey])->getJson('/api/v1/test-endpoint')->assertStatus(429);
    });

    it('stops at the subscriber spend cap when it is lower than the plan cap', function () {
        // R$ 1,00 a R$ 0,10 por requisição = 10 requisições além do limite.
        $this->user->forceFill(['overage_spend_cap' => 1.00])->save();
        $userPlan = subscription(['end_date' => now()->addDays(10), 'requests_used' => 109]);
        $key = ApiKey::factory()->create(['authentication_id' => $this->user->getKey(), 'active' => true]);

        $this->withHeaders(['X-API-KEY' => $key->plainKey])->getJson('/api/v1/test-endpoint')->assertOk();
        $this->withHeaders(['X-API-KEY' => $key->plainKey])->getJson('/api/v1/test-endpoint')->assertStatus(429);

        expect($userPlan->fresh()->requests_used)->toBe(110);
    });

    it('warns once when the account enters overage', function () {
        subscription(['end_date' => now()->addDays(10), 'requests_used' => 99]);
        $key = ApiKey::factory()->create(['authentication_id' => $this->user->getKey(), 'active' => true]);

        foreach (range(1, 3) as $_) {
            $this->withHeaders(['X-API-KEY' => $key->plainKey])->getJson('/api/v1/test-endpoint')->assertOk();
        }

        Notification::assertSentToTimes($this->user, OverageStartedNotification::class, 1);
    });

    it('lets the subscriber turn overage on and off from the dashboard', function () {
        $this->user->forceFill(['overage_enabled' => false])->save();
        subscription(['end_date' => now()->addDays(10)]);
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/dashboard/billing/overage')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.extra_allowed', 0);

        $this->putJson('/api/v1/dashboard/billing/overage', ['enabled' => true, 'spend_cap' => 2])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.extra_allowed', 20);

        expect(AuditLog::where('action', 'billing.overage_preferences_changed')->exists())->toBeTrue();
    });

    it('keeps serving past the limit up to the overage cap', function () {
        $userPlan = subscription(['end_date' => now()->addDays(10), 'requests_used' => 149]);
        $key = ApiKey::factory()->create(['authentication_id' => $this->user->getKey(), 'active' => true]);

        $this->withHeaders(['X-API-KEY' => $key->plainKey])->getJson('/api/v1/test-endpoint')->assertOk();
        $this->withHeaders(['X-API-KEY' => $key->plainKey])->getJson('/api/v1/test-endpoint')->assertStatus(429);

        expect($userPlan->fresh()->requests_used)->toBe(150);
    });

    it('still blocks at the limit on a plan without overage price', function () {
        $this->plan->update(['overage_price' => null]);
        subscription(['end_date' => now()->addDays(10), 'requests_used' => 100]);
        $key = ApiKey::factory()->create(['authentication_id' => $this->user->getKey(), 'active' => true]);

        $this->withHeaders(['X-API-KEY' => $key->plainKey])->getJson('/api/v1/test-endpoint')->assertStatus(429);
    });

    it('charges the closed period on the saved card, once', function () {
        savedCard();
        $userPlan = subscription(['end_date' => now()->subDay(), 'requests_used' => 130]);
        $this->gateway->pushResponse(['id' => 8001, 'status' => 'approved']);

        $this->artisan('api-key:billing-charge-overage')->assertSuccessful();
        $this->artisan('api-key:billing-charge-overage')->assertSuccessful();

        $userPlan->refresh();
        expect($userPlan->overage_requests)->toBe(30)
            ->and((float) $userPlan->overage_amount)->toBe(3.0)
            ->and($userPlan->overage_paid_at)->not->toBeNull()
            ->and($this->gateway->requests)->toHaveCount(1)
            ->and($this->gateway->payload()['transaction_amount'])->toEqual(3.0);

        Notification::assertSentTo($this->user, OverageChargeNotification::class, fn ($n) => $n->state === 'paid');
    });

    it('asks a subscriber without card to pay the overage in the dashboard, then pays it with PIX there', function () {
        config(['api-key.mercadopago.pix.enabled' => true]);
        $userPlan = subscription(['end_date' => now()->subDay(), 'requests_used' => 130]);

        $this->artisan('api-key:billing-charge-overage')->assertSuccessful();

        expect($this->gateway->requests)->toBe([]);
        Notification::assertSentTo($this->user, OverageChargeNotification::class, fn ($n) => $n->state === 'payment_required');

        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/dashboard/billing/overage')
            ->assertJsonPath('data.unpaid.0.id', $userPlan->getKey())
            ->assertJsonPath('data.unpaid.0.amount', 3);

        $this->gateway->pushResponse([
            'id' => 8101,
            'status' => 'pending',
            'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix-excedente']],
        ]);

        $this->postJson("/api/v1/dashboard/billing/overage/{$userPlan->getKey()}/pix")
            ->assertOk()
            ->assertJsonPath('data.qr_code', 'pix-excedente')
            ->assertJsonPath('data.payment_id', '8101');

        expect($this->gateway->payload()['external_reference'])->toStartWith('overage|');

        // Confirmação pelo webhook quita o excedente.
        app(PaymentOutcomeService::class)->apply((object) [
            'id' => 8101,
            'status' => 'approved',
            'payment_method_id' => 'pix',
            'transaction_amount' => 3,
            'external_reference' => $this->gateway->payload()['external_reference'],
        ]);

        expect($userPlan->fresh()->overage_paid_at)->not->toBeNull();
        $this->getJson('/api/v1/dashboard/billing/overage')->assertJsonPath('data.unpaid', []);
    });

    it('treats the CVV refusal on overage as payment required, not a card rejection', function () {
        savedCard();
        subscription(['end_date' => now()->subDay(), 'requests_used' => 130]);
        $this->gateway->pushFailure(['message' => "security_code_id can't be null", 'cause' => [['code' => 3031]]]);

        $this->artisan('api-key:billing-charge-overage')->assertSuccessful();

        Notification::assertSentTo($this->user, OverageChargeNotification::class, fn ($n) => $n->state === 'payment_required');
    });

    it('pays an open overage with a saved card typing the CVV, without e-mail', function () {
        $card = savedCard();
        $userPlan = subscription(['end_date' => now()->subDay(), 'requests_used' => 130, 'overage_requests' => 30, 'overage_amount' => 3, 'overage_billed_at' => now()]);
        $this->gateway->pushResponse(['id' => 8202, 'status' => 'approved']);

        Sanctum::actingAs($this->user);

        $this->postJson("/api/v1/dashboard/billing/overage/{$userPlan->getKey()}/card", ['card_id' => $card->getKey(), 'token' => 'cvv-token-1', 'idempotency_key' => 'try-1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        expect($this->gateway->payload()['token'])->toBe('cvv-token-1')
            ->and($this->gateway->payload()['external_reference'])->toStartWith('overage|')
            ->and($userPlan->fresh()->overage_paid_at)->not->toBeNull();
        Notification::assertNotSentTo($this->user, OverageChargeNotification::class);
        $this->getJson('/api/v1/dashboard/billing/overage')->assertJsonPath('data.unpaid', []);
    });

    it('asks for the CVV when the account refuses the card without it', function () {
        $card = savedCard();
        $userPlan = subscription(['end_date' => now()->subDay(), 'requests_used' => 130, 'overage_requests' => 30, 'overage_amount' => 3, 'overage_billed_at' => now()]);
        $this->gateway->pushFailure(['message' => "security_code_id can't be null", 'cause' => [['code' => 3031]]]);

        Sanctum::actingAs($this->user);

        $this->postJson("/api/v1/dashboard/billing/overage/{$userPlan->getKey()}/card", ['card_id' => $card->getKey()])
            ->assertStatus(422)
            ->assertJsonPath('status', 'cvv_required');

        expect($userPlan->fresh()->overage_paid_at)->toBeNull();
    });

    it('pays an open overage with a card of the active non-Mercado Pago gateway, without CVV', function () {
        config(['api-key.payment.gateway' => 'stripe', 'api-key.stripe.secret_key' => 'sk_test_1']);
        $card = UserCard::create([
            'authentication_id' => $this->user->getKey(), 'holder_name' => 'ANA', 'last_four' => '4242', 'brand' => 'visa',
            'expiry_month' => 12, 'expiry_year' => 2030, 'gateway' => 'stripe', 'gateway_customer_id' => 'cus_1', 'gateway_card_id' => 'pm_1', 'is_default' => true,
        ]);
        $userPlan = subscription(['end_date' => now()->subDay(), 'requests_used' => 130, 'overage_requests' => 30, 'overage_amount' => 3, 'overage_billed_at' => now()]);
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_OV', 'status' => 'succeeded', 'amount' => 300, 'payment_method_types' => ['card']])]);

        Sanctum::actingAs($this->user);

        $this->postJson("/api/v1/dashboard/billing/overage/{$userPlan->getKey()}/card", ['card_id' => $card->getKey()])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        expect($userPlan->fresh()->overage_payment_id)->toBe('pi_OV');
    });

    it('does not let someone pay with another user card', function () {
        $other = Authentication::factory()->create();
        $card = UserCard::create([
            'authentication_id' => $other->getKey(), 'holder_name' => 'X', 'last_four' => '1111', 'brand' => 'visa',
            'expiry_month' => 12, 'expiry_year' => 2030, 'mp_customer_id' => 'cust-2', 'mp_card_id' => 'card-2', 'is_default' => true,
        ]);
        $userPlan = subscription(['end_date' => now()->subDay(), 'requests_used' => 130, 'overage_requests' => 30, 'overage_amount' => 3, 'overage_billed_at' => now()]);

        Sanctum::actingAs($this->user);

        $this->postJson("/api/v1/dashboard/billing/overage/{$userPlan->getKey()}/card", ['card_id' => $card->getKey()])
            ->assertStatus(422);

        expect($this->gateway->requests)->toBe([]);
    });

    it('waives an amount below the minimum charge', function () {
        savedCard();
        $userPlan = subscription(['end_date' => now()->subDay(), 'requests_used' => 105]);

        $this->artisan('api-key:billing-charge-overage')->assertSuccessful();

        expect($userPlan->fresh()->overage_billed_at)->not->toBeNull()
            ->and($this->gateway->requests)->toBe([]);
    });
});

describe('Receipts', function () {
    it('lists and renders the receipt of a paid subscription', function () {
        $userPlan = subscription(['payment_id' => '555', 'payment_amount' => 50, 'payment_method' => 'pix']);
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/dashboard/receipts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $userPlan->getKey());

        $this->get("/api/v1/dashboard/receipts/{$userPlan->getKey()}")
            ->assertOk()
            ->assertSee('Recibo de pagamento')
            ->assertSee('R$ 50,00')
            ->assertSee('PIX');
    });

    it('does not show another user receipt', function () {
        $other = Authentication::factory()->create();
        $userPlan = UserPlan::factory()->create([
            'authentication_id' => $other->getKey(),
            'plan_id' => $this->plan->getKey(),
            'payment_id' => '777',
        ]);
        Sanctum::actingAs($this->user);

        $this->get("/api/v1/dashboard/receipts/{$userPlan->getKey()}")->assertNotFound();
    });
});

describe('Renewal in the dashboard', function () {
    it('tells the dashboard a renewal is waiting, even during the grace period', function () {
        $userPlan = subscription([
            'end_date' => now()->subDay(),
            'renewal_requires_payment' => 'cvv_required',
            'renewal_failed_at' => now()->subDay(),
        ]);
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/dashboard/billing/renewal')
            ->assertOk()
            ->assertJsonPath('data.required', true)
            ->assertJsonPath('data.reason', 'cvv_required')
            ->assertJsonPath('data.plan.id', $this->plan->getKey());

        // Pagar pelo checkout abre o período novo e encerra o pedido.
        $this->user->subscribeToPlan($this->plan, ['payment_id' => 'p-renew', 'payment_amount' => 50]);

        $this->getJson('/api/v1/dashboard/billing/renewal')->assertJsonPath('data.required', false);
        expect($userPlan->fresh()->active)->toBeFalse();
    });
});
