<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use RiseTechApps\ApiKey\Jobs\ProcessPlanRenewalJob;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\PendingPayment\PendingPayment;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\Setting\Setting;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Notifications\RenewalFailedNotification;
use RiseTechApps\ApiKey\Services\MpCustomerService;
use RiseTechApps\ApiKey\Services\PaymentSettingsService;
use RiseTechApps\ApiKey\Services\RefundService;
use RiseTechApps\ApiKey\Tests\Support\FakeMercadoPagoHttpClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    $this->gateway = new FakeMercadoPagoHttpClient;
    MercadoPagoConfig::setHttpClient($this->gateway);
    PaymentSettingsService::forget();

    config([
        'api-key.payment.gateway' => 'pagarme',
        'api-key.pagarme.secret_key' => 'sk_test_123',
        'api-key.pagarme.public_key' => 'pk_test_123',
        'api-key.pagarme.pix.enabled' => true,
    ]);

    $this->user = Authentication::factory()->create(['cpf' => '529.982.247-25', 'cellphone' => '(21) 98765-4321']);
    $this->plan = Plan::factory()->create(['price' => 50.00, 'request_limit' => 100, 'is_active' => true]);
});

afterEach(function () {
    MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient);
});

/** Pedido como a API v5 do Pagar.me devolve. */
function pgOrder(string $status, string $reference, float $amount = 50.0, string $method = 'credit_card', array $transaction = [], string $id = 'or_TEST1'): array
{
    return [
        'id' => $id,
        'status' => $status,
        'amount' => (int) round($amount * 100),
        'metadata' => ['reference' => $reference],
        'charges' => [[
            'id' => 'ch_TEST1',
            'status' => $status === 'paid' ? 'paid' : ($status === 'failed' ? 'failed' : 'pending'),
            'amount' => (int) round($amount * 100),
            'payment_method' => $method,
            'last_transaction' => ['status' => $status === 'paid' ? 'captured' : 'not_authorized', 'card' => ['brand' => 'Visa'], ...$transaction],
        ]],
    ];
}

function pgCard(array $attributes = []): UserCard
{
    return UserCard::create([
        'authentication_id' => test()->user->getKey(),
        'holder_name' => 'ANA',
        'last_four' => '4242',
        'brand' => 'visa',
        'expiry_month' => '12',
        'expiry_year' => '2030',
        'is_default' => true,
        'gateway' => 'pagarme',
        'gateway_customer_id' => 'cus_1',
        'gateway_card_id' => 'card_1',
        ...$attributes,
    ]);
}

function pgSubscription(array $attributes = []): UserPlan
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

describe('Payment settings in the admin panel', function () {
    beforeEach(function () {
        $this->admin = Authentication::factory()->create(['role' => 'admin']);
    });

    it('stores credentials encrypted and never sends a secret back', function () {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/dashboard/admin/payment-settings', ['values' => [
                'payment.gateway' => 'mercadopago',
                'pagarme.secret_key' => 'sk_live_supersecret_9876',
                'pagarme.pix.enabled' => false,
            ]])
            ->assertOk();

        $values = $response->json('data.values');

        expect($values['payment.gateway'])->toBe('mercadopago')
            ->and($values['pagarme.secret_key'])->toBe(['set' => true, 'hint' => '…9876'])
            ->and($values['pagarme.pix.enabled'])->toBeFalse()
            ->and($response->getContent())->not->toContain('supersecret');

        $raw = DB::table('api_key_settings')->where('key', 'pagarme.secret_key')->value('value');

        expect($raw)->not->toContain('supersecret')
            ->and(Setting::where('key', 'pagarme.secret_key')->first()->value)->toBe('sk_live_supersecret_9876')
            ->and(config('api-key.pagarme.secret_key'))->toBe('sk_live_supersecret_9876')
            ->and(config('api-key.payment.gateway'))->toBe('mercadopago');
    });

    it('keeps the current secret when the field comes blank', function () {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/dashboard/admin/payment-settings', ['values' => ['pagarme.secret_key' => 'sk_first']])->assertOk();
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/dashboard/admin/payment-settings', ['values' => ['pagarme.secret_key' => '']])->assertOk();

        expect(config('api-key.pagarme.secret_key'))->toBe('sk_first');
    });

    it('rejects an unknown gateway', function () {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/dashboard/admin/payment-settings', ['values' => ['payment.gateway' => 'paypal']])
            ->assertStatus(422);
    });

    it('is closed to non-admins', function () {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/dashboard/admin/payment-settings')
            ->assertForbidden();
    });

    it('tests the Pagar.me connection', function () {
        Http::fake(['api.pagar.me/core/v5/customers*' => Http::response(['data' => []])]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/dashboard/admin/payment-settings/test/pagarme')
            ->assertOk()
            ->assertJsonPath('data.ok', true);
    });
});

describe('Pagar.me card', function () {
    beforeEach(fn () => $this->actingAs($this->user, 'sanctum'));

    it('saves the card with zero-amount verification and no validation charge', function () {
        Http::fake([
            'api.pagar.me/core/v5/customers' => Http::response(['id' => 'cus_9']),
            'api.pagar.me/core/v5/customers/cus_9/cards' => Http::response([
                'id' => 'card_9', 'last_four_digits' => '1111', 'brand' => 'Mastercard', 'exp_month' => 3, 'exp_year' => 31, 'holder_name' => 'ANA',
            ]),
        ]);

        $this->postJson('/api/v1/dashboard/cards', ['card_token' => 'token_abc', 'cpf' => '529.982.247-25', 'holder_name' => 'ANA'])
            ->assertStatus(201)
            ->assertJsonPath('data.automatic_billing', true)
            ->assertJsonPath('data.brand', 'mastercard')
            ->assertJsonMissingPath('data.gateway_card_id');

        $card = UserCard::first();
        expect($card->gateway)->toBe('pagarme')
            ->and($card->gateway_card_id)->toBe('card_9')
            ->and($card->expiry_year)->toBe('2031')
            ->and($this->gateway->requests)->toBe([]);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/cards') && $r['token'] === 'token_abc' && $r['options']['verify_card'] === true);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/customers') && $r['code'] === $this->user->getKey() && $r['phones']['mobile_phone']['area_code'] === '21');
    });

    it('explains a card refused in the verification', function () {
        Http::fake([
            'api.pagar.me/core/v5/customers' => Http::response(['id' => 'cus_9']),
            'api.pagar.me/core/v5/customers/cus_9/cards' => Http::response(['message' => 'card verification failed'], 412),
        ]);

        $this->postJson('/api/v1/dashboard/cards', ['card_token' => 'token_abc', 'cpf' => '529.982.247-25', 'holder_name' => 'ANA'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api-key::messages.card_verification_failed'));
    });

    it('removes the card from Pagar.me', function () {
        $card = pgCard();
        Http::fake(['api.pagar.me/core/v5/customers/cus_1/cards/card_1' => Http::response([])]);

        $this->deleteJson("/api/v1/dashboard/cards/{$card->getKey()}")->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/customers/cus_1/cards/card_1'));
    });
});

describe('Pagar.me checkout', function () {
    beforeEach(fn () => $this->actingAs($this->user, 'sanctum'));

    it('charges a new card as the first payment of the recurrence', function () {
        Http::fake([
            'api.pagar.me/core/v5/customers' => Http::response(['id' => 'cus_9']),
            'api.pagar.me/core/v5/customers/cus_9/cards' => Http::response(['id' => 'card_9', 'last_four_digits' => '1111', 'brand' => 'Visa', 'exp_month' => 3, 'exp_year' => 2031]),
            'api.pagar.me/core/v5/orders' => Http::response(pgOrder('paid', 'x')),
        ]);

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            'card_token' => 'token_abc',
            'cpf' => '529.982.247-25',
            'holder_name' => 'ANA',
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        expect(UserPlan::where('payment_id', 'or_TEST1')->where('active', true)->exists())->toBeTrue()
            ->and(UserCard::first()->gateway_first_charge_id)->toBe('ch_TEST1')
            ->and($this->gateway->requests)->toBe([]);

        Http::assertSent(function (Request $r) {
            $card = $r['payments'][0]['credit_card'] ?? [];

            return str_ends_with($r->url(), '/orders')
                && $card['card_id'] === 'card_9'
                && $card['recurrence_cycle'] === 'first'
                && ! isset($card['payment_origin'])
                && $r['items'][0]['amount'] === 5000
                && $r['metadata']['reference'] === $this->user->getKey().'|'.$this->plan->getKey()
                && $r->hasHeader('Idempotency-Key');
        });
    });

    it('releases nothing and answers 422 when the card is declined', function () {
        pgCard();
        Http::fake(['api.pagar.me/core/v5/orders' => Http::response(pgOrder('failed', 'x'))]);

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            'saved_card_id' => UserCard::first()->getKey(),
        ])->assertStatus(422);

        expect(UserPlan::where('payment_id', 'or_TEST1')->exists())->toBeFalse();
    });

    it('asks for CPF and phone before a PIX', function () {
        $this->user->forceFill(['cellphone' => null, 'telephone' => null])->save();

        $this->postJson('/api/v1/dashboard/checkout/process', ['plan_id' => $this->plan->getKey(), 'payment_method' => 'pix'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api-key::messages.pix_requires_document_and_phone'));
    });

    it('returns the PIX QR code and settles it from the webhook', function () {
        Http::fake([
            'api.pagar.me/core/v5/orders' => Http::response(pgOrder('pending', 'x', method: 'pix', transaction: ['qr_code' => 'pix-copia-e-cola', 'qr_code_url' => 'https://api.pagar.me/qr.png'])),
            'api.pagar.me/qr.png' => Http::response('PNG'),
            'api.pagar.me/core/v5/orders/or_TEST1' => Http::response(pgOrder('paid', $this->user->getKey().'|'.$this->plan->getKey(), method: 'pix')),
        ]);

        $this->postJson('/api/v1/dashboard/checkout/process', ['plan_id' => $this->plan->getKey(), 'payment_method' => 'pix'])
            ->assertOk()
            ->assertJsonPath('data.status', 'pix')
            ->assertJsonPath('data.payment_id', 'or_TEST1')
            ->assertJsonPath('data.qr_code', 'pix-copia-e-cola')
            ->assertJsonPath('data.qr_code_base64', base64_encode('PNG'));

        expect(PendingPayment::where('payment_id', 'or_TEST1')->exists())->toBeTrue();

        $this->postJson('/api/v1/dashboard/checkout/webhook/pagarme', ['id' => 'hook_1', 'type' => 'order.paid', 'data' => ['id' => 'or_TEST1']])
            ->assertOk();

        expect(UserPlan::where('payment_id', 'or_TEST1')->where('active', true)->exists())->toBeTrue();
    });
});

describe('Pagar.me webhook', function () {
    it('requires the configured basic auth', function () {
        config(['api-key.pagarme.webhook_user' => 'hook', 'api-key.pagarme.webhook_password' => 's3cret']);

        $this->postJson('/api/v1/dashboard/checkout/webhook/pagarme', ['type' => 'order.paid', 'data' => ['id' => 'or_TEST1']])
            ->assertStatus(401);

        Http::fake(['api.pagar.me/core/v5/orders/or_TEST1' => Http::response([], 404)]);

        $this->withHeaders(['Authorization' => 'Basic '.base64_encode('hook:s3cret')])
            ->postJson('/api/v1/dashboard/checkout/webhook/pagarme', ['type' => 'order.paid', 'data' => ['id' => 'or_TEST1']])
            ->assertOk();
    });

    it('never trusts the body: a forged paid order is checked against the API', function () {
        $pending = PendingPayment::create([
            'authentication_id' => $this->user->getKey(),
            'plan_id' => $this->plan->getKey(),
            'payment_id' => 'or_TEST1',
            'amount' => 50,
            'status' => 'pending',
        ]);
        Http::fake(['api.pagar.me/core/v5/orders/or_TEST1' => Http::response(pgOrder('pending', $this->user->getKey().'|'.$this->plan->getKey(), method: 'pix'))]);

        $this->postJson('/api/v1/dashboard/checkout/webhook/pagarme', [
            'type' => 'order.paid',
            'data' => ['id' => 'or_TEST1', 'status' => 'paid'],
        ])->assertOk();

        expect(UserPlan::where('payment_id', 'or_TEST1')->exists())->toBeFalse()
            ->and($pending->fresh()->settled_at)->toBeNull();
    });

    it('caps the API lookups that unauthenticated notifications trigger', function () {
        // Sem usuário e senha, qualquer um manda o aviso, e cada aviso falso
        // gastaria uma consulta à API do Pagar.me.
        config(['api-key.payment.unsigned_notification_lookups_per_minute' => 2]);
        Http::fake(['api.pagar.me/core/v5/orders/*' => Http::response([], 404)]);

        foreach (['or_A', 'or_B'] as $id) {
            $this->postJson('/api/v1/dashboard/checkout/webhook/pagarme', ['type' => 'order.paid', 'data' => ['id' => $id]])->assertOk();
        }

        $this->postJson('/api/v1/dashboard/checkout/webhook/pagarme', ['type' => 'order.paid', 'data' => ['id' => 'or_C']])
            ->assertStatus(503);

        Http::assertSentCount(2);
    });

    it('lets notifications from a trusted IP through a full budget', function () {
        config([
            'api-key.payment.unsigned_notification_lookups_per_minute' => 1,
            'api-key.payment.notification_trusted_ips' => ['203.0.113.10'],
        ]);
        Http::fake(['api.pagar.me/core/v5/orders/*' => Http::response([], 404)]);

        $this->postJson('/api/v1/dashboard/checkout/webhook/pagarme', ['type' => 'order.paid', 'data' => ['id' => 'or_A']])->assertOk();
        $this->postJson('/api/v1/dashboard/checkout/webhook/pagarme', ['type' => 'order.paid', 'data' => ['id' => 'or_B']])->assertStatus(503);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/v1/dashboard/checkout/webhook/pagarme', ['type' => 'order.paid', 'data' => ['id' => 'or_C']])
            ->assertOk();

        Http::assertSentCount(2);
    });

    it('does not cap notifications that prove their origin', function () {
        config([
            'api-key.payment.unsigned_notification_lookups_per_minute' => 1,
            'api-key.pagarme.webhook_user' => 'hook',
            'api-key.pagarme.webhook_password' => 's3cret',
        ]);
        Http::fake(['api.pagar.me/core/v5/orders/*' => Http::response([], 404)]);

        foreach (['or_A', 'or_B', 'or_C'] as $id) {
            $this->withHeaders(['Authorization' => 'Basic '.base64_encode('hook:s3cret')])
                ->postJson('/api/v1/dashboard/checkout/webhook/pagarme', ['type' => 'order.paid', 'data' => ['id' => $id]])
                ->assertOk();
        }

        Http::assertSentCount(3);
    });
});

describe('Pagar.me renewal', function () {
    it('charges the saved card without CVV, continuing the recurrence', function () {
        pgCard(['gateway_first_charge_id' => 'ch_FIRST']);
        $userPlan = pgSubscription();
        Http::fake(['api.pagar.me/core/v5/orders' => Http::response(pgOrder('paid', 'x', id: 'or_RENEW'))]);

        (new ProcessPlanRenewalJob($userPlan->getKey()))->handle(app(MpCustomerService::class));

        expect(UserPlan::where('payment_id', 'or_RENEW')->where('active', true)->exists())->toBeTrue()
            ->and($this->gateway->requests)->toBe([]);

        Http::assertSent(function (Request $r) use ($userPlan) {
            $card = $r['payments'][0]['credit_card'] ?? [];

            return $card['recurrence_cycle'] === 'subsequent'
                && $card['payment_origin'] === ['charge_id' => 'ch_FIRST']
                && ! isset($card['card']['cvv'])
                && str_starts_with($r['metadata']['reference'], 'renewal|')
                && str_ends_with($r['metadata']['reference'], $userPlan->getKey());
        });
    });

    it('schedules a retry when the renewal is declined', function () {
        pgCard(['gateway_first_charge_id' => 'ch_FIRST']);
        $userPlan = pgSubscription();
        Http::fake(['api.pagar.me/core/v5/orders' => Http::response(pgOrder('failed', 'x'))]);

        (new ProcessPlanRenewalJob($userPlan->getKey()))->handle(app(MpCustomerService::class));

        expect($userPlan->fresh()->renewal_attempts)->toBe(1);
        Notification::assertSentTo($this->user, RenewalFailedNotification::class);
    });

    it('stops charging a Mercado Pago card after switching to Pagar.me', function () {
        UserCard::create([
            'authentication_id' => $this->user->getKey(), 'holder_name' => 'ANA', 'last_four' => '4242', 'brand' => 'visa',
            'expiry_month' => '12', 'expiry_year' => '2030', 'mp_customer_id' => 'cust-1', 'mp_card_id' => 'card-1', 'is_default' => true,
        ]);
        $userPlan = pgSubscription();
        config(['api-key.mercadopago.access_token' => 'TEST-not-a-real-token']);
        Http::fake();

        (new ProcessPlanRenewalJob($userPlan->getKey()))->handle(app(MpCustomerService::class));

        // Só o gateway ativo cobra: o assinante cadastra um cartão novo.
        expect($this->gateway->requests)->toBe([])
            ->and($userPlan->fresh()->renewal_requires_payment)->toBe('card_out_of_use');
        Http::assertNothingSent();
    });

    it('sends the subscriber to the dashboard when Pagar.me is no longer configured', function () {
        config(['api-key.pagarme.secret_key' => null]);
        pgCard();
        $userPlan = pgSubscription();

        (new ProcessPlanRenewalJob($userPlan->getKey()))->handle(app(MpCustomerService::class));

        expect($userPlan->fresh()->renewal_requires_payment)->toBe('cvv_required');
    });
});

describe('Pagar.me refund', function () {
    it('cancels the charge of the order', function () {
        $userPlan = pgSubscription(['payment_id' => 'or_TEST1', 'payment_amount' => 50]);
        Http::fake([
            'api.pagar.me/core/v5/orders/or_TEST1' => Http::response(pgOrder('paid', 'x')),
            'api.pagar.me/core/v5/charges/ch_TEST1' => Http::response(['id' => 'ch_TEST1', 'status' => 'canceled', 'last_transaction' => ['id' => 'tran_REF']]),
        ]);

        $refundId = app(RefundService::class)->refund($userPlan, 20.0);

        expect($refundId)->toBe('tran_REF')
            ->and($userPlan->fresh()->refunded_at)->not->toBeNull();

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/charges/ch_TEST1') && $r['amount'] === 2000);
    });
});
