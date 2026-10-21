<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use RiseTechApps\ApiKey\Jobs\ProcessPlanRenewalJob;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\PendingPayment\PendingPayment;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Notifications\OverageChargeNotification;
use RiseTechApps\ApiKey\Notifications\RenewalFailedNotification;
use RiseTechApps\ApiKey\Services\MpCustomerService;
use RiseTechApps\ApiKey\Services\RefundService;
use RiseTechApps\ApiKey\Tests\Support\FakeMercadoPagoHttpClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    $this->gateway = new FakeMercadoPagoHttpClient;
    MercadoPagoConfig::setHttpClient($this->gateway);
    config([
        'api-key.mercadopago.access_token' => 'TEST-not-a-real-token',
        'api-key.mercadopago.webhook_secret' => 'automatic-secret',
    ]);

    $this->user = Authentication::factory()->create();
    $this->plan = Plan::factory()->create(['price' => 50.00, 'request_limit' => 100]);
});

afterEach(function () {
    MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient);
});

function apCard(bool $withProfile = true): UserCard
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
        'mp_payment_profile_id' => $withProfile ? 'prof-1' : null,
        'mp_payment_profile_status' => $withProfile ? 'READY' : null,
    ]);
}

function apSubscription(array $attributes = []): UserPlan
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

/** Order como a Orders API devolve. */
function apOrder(string $status, string $reference, float $amount = 50.0, string $detail = 'accredited', string $id = 'ORD01TEST'): array
{
    return [
        'id' => $id,
        'status' => $status,
        'status_detail' => $detail,
        'external_reference' => $reference,
        'total_amount' => number_format($amount, 2, '.', ''),
        'transactions' => ['payments' => [[
            'id' => 'PAY01TEST',
            'amount' => number_format($amount, 2, '.', ''),
            'status' => $status,
            'status_detail' => $detail,
            'payment_method' => ['id' => 'visa', 'type' => 'credit_card'],
        ]]],
    ];
}

function apRunRenewal(UserPlan $userPlan): void
{
    (new ProcessPlanRenewalJob($userPlan->getKey()))->handle(app(MpCustomerService::class));
}

describe('Renewal with automatic payments', function () {
    it('charges the payment profile without CVV or card token', function () {
        apCard();
        $userPlan = apSubscription();
        Http::fake(['*/v1/orders' => Http::response(apOrder('processed', "rn-{$userPlan->getKey()}"), 201)]);

        apRunRenewal($userPlan);

        expect(UserPlan::where('payment_id', 'ORD01TEST')->where('active', true)->exists())->toBeTrue()
            ->and($this->gateway->requests)->toBe([]);

        Http::assertSent(function (Request $request) use ($userPlan) {
            $payment = $request['transactions']['payments'][0] ?? [];

            return str_ends_with($request->url(), '/v1/orders')
                && $request['external_reference'] === "rn-{$userPlan->getKey()}"
                && $request['total_amount'] === '50.00'
                && $payment['automatic_payments']['payment_profile_id'] === 'prof-1'
                && $payment['stored_credential'] === ['payment_initiator' => 'merchant', 'reason' => 'recurring', 'first_payment' => false]
                && ! isset($payment['payment_method']['token'])
                && $request->hasHeader('X-Idempotency-Key');
        });
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/v1/card_tokens'));
    });

    it('falls back to the saved card token when the account is not authorized', function () {
        apCard();
        $userPlan = apSubscription();
        Http::fake([
            '*/v1/orders' => Http::response(['errors' => [['code' => 'action_not_allowed', 'message' => 'The application is not authorized to perform this type of payment.']]], 403),
            '*/v1/card_tokens' => Http::response(['id' => 'card-token-1']),
        ]);
        $this->gateway->pushResponse(['id' => 4, 'status' => 'approved']);

        apRunRenewal($userPlan);

        expect(UserPlan::where('payment_id', '4')->where('active', true)->exists())->toBeTrue()
            ->and($this->gateway->requests)->toHaveCount(1);
    });

    it('keeps the CVV-required path when the account is not authorized', function () {
        apCard();
        $userPlan = apSubscription();
        Http::fake([
            '*/v1/orders' => Http::response(['errors' => [['code' => 'action_not_allowed']]], 403),
            '*/v1/card_tokens' => Http::response(['id' => 'card-token-1']),
        ]);
        $this->gateway->pushFailure(['message' => "security_code_id can't be null", 'cause' => [['code' => 3031]]]);

        apRunRenewal($userPlan);

        expect($userPlan->fresh()->renewal_requires_payment)->toBe('cvv_required');
    });

    it('schedules a retry when the order is declined', function () {
        apCard();
        $userPlan = apSubscription();
        Http::fake(['*/v1/orders' => Http::response(apOrder('failed', "rn-{$userPlan->getKey()}", detail: 'insufficient_amount'), 402)]);

        apRunRenewal($userPlan);

        $userPlan->refresh();
        expect($userPlan->renewal_attempts)->toBe(1)
            ->and($userPlan->next_renewal_attempt_at)->not->toBeNull();

        Notification::assertSentTo($this->user, RenewalFailedNotification::class, fn ($n) => $n->reason === __('api-key::messages.payment_rejected.cc_rejected_insufficient_amount'));
    });

    it('records a processing order as pending and settles it from the order webhook', function () {
        apCard();
        $userPlan = apSubscription();
        Http::fake([
            '*/v1/orders' => Http::response(apOrder('processing', "rn-{$userPlan->getKey()}"), 201),
            '*/v1/orders/ORD01TEST' => Http::response(apOrder('processed', "rn-{$userPlan->getKey()}")),
        ]);

        apRunRenewal($userPlan);

        expect(PendingPayment::where('payment_id', 'ORD01TEST')->exists())->toBeTrue()
            ->and(UserPlan::where('payment_id', 'ORD01TEST')->exists())->toBeFalse();

        $ts = '1700000000';
        $hash = hash_hmac('sha256', 'id:ord01test;request-id:req-1;ts:'.$ts.';', 'automatic-secret');

        $this->withHeaders(['x-signature' => "ts={$ts},v1={$hash}", 'x-request-id' => 'req-1'])
            ->postJson('/api/v1/dashboard/checkout/webhook', ['type' => 'order', 'data' => ['id' => 'ORD01TEST']])
            ->assertOk();

        expect(UserPlan::where('payment_id', 'ORD01TEST')->where('active', true)->exists())->toBeTrue()
            ->and(PendingPayment::where('payment_id', 'ORD01TEST')->first()->settled_at)->not->toBeNull();
    });

    it('uses the old path when automatic payments are turned off', function () {
        config(['api-key.mercadopago.automatic_payments.enabled' => false]);
        apCard();
        $userPlan = apSubscription();
        Http::fake(['*/v1/card_tokens' => Http::response(['id' => 'card-token-1'])]);
        $this->gateway->pushResponse(['id' => 5, 'status' => 'approved']);

        apRunRenewal($userPlan);

        expect(UserPlan::where('payment_id', '5')->exists())->toBeTrue();
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/v1/orders'));
    });

    it('uses the old path for a card without payment profile', function () {
        apCard(withProfile: false);
        $userPlan = apSubscription();
        Http::fake(['*/v1/card_tokens' => Http::response(['id' => 'card-token-1'])]);
        $this->gateway->pushResponse(['id' => 6, 'status' => 'approved']);

        apRunRenewal($userPlan);

        expect(UserPlan::where('payment_id', '6')->exists())->toBeTrue();
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/v1/orders'));
    });
});

describe('Overage with automatic payments', function () {
    beforeEach(function () {
        $this->plan->update(['overage_price' => 0.10, 'overage_max_requests' => 1000]);
        $this->user->forceFill(['overage_enabled' => true])->save();
    });

    it('charges the closed period through the payment profile', function () {
        apCard();
        $userPlan = apSubscription(['end_date' => now()->subDay(), 'requests_used' => 130]);
        Http::fake(['*/v1/orders' => Http::response(apOrder('processed', "ov-{$userPlan->getKey()}", 3.0, id: 'ORD01OVER'), 201)]);

        $this->artisan('billing:charge-overage')->assertSuccessful();

        $userPlan->refresh();
        expect($userPlan->overage_payment_id)->toBe('ORD01OVER')
            ->and($userPlan->overage_paid_at)->not->toBeNull()
            ->and($this->gateway->requests)->toBe([]);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/orders')
            && $request['external_reference'] === "ov-{$userPlan->getKey()}"
            && $request['total_amount'] === '3.00');
        Notification::assertSentTo($this->user, OverageChargeNotification::class, fn ($n) => $n->state === 'paid');
    });
});

describe('Payment profile', function () {
    beforeEach(function () {
        $this->actingAs($this->user, 'sanctum');
    });

    function apValidationCharge(): array
    {
        return [
            'id' => 700100,
            'status' => 'approved',
            'status_detail' => 'accredited',
            'transaction_amount' => 5.00,
            'card' => ['last_four_digits' => '4321', 'expiration_month' => 8, 'expiration_year' => 2030],
        ];
    }

    function apStoreCard(array $overrides = []): array
    {
        return [
            'mp_token' => 'tok_1',
            'cpf' => '529.982.247-25',
            'payment_method_id' => 'visa',
            'holder_name' => 'Fulano de Tal',
            'brand' => 'visa',
            ...$overrides,
        ];
    }

    it('is created with the second token when a card is saved', function () {
        Http::fake([
            'api.mercadopago.com/v1/customers' => Http::response(['id' => 'cus_1'], 201),
            'api.mercadopago.com/v1/customers/*/cards' => Http::response(['id' => 'card_mp_1'], 201),
            'api.mercadopago.com/v1/customers/*/payment-profiles' => Http::response(['id' => 'prof-9', 'status' => 'READY'], 201),
        ]);
        $this->gateway->pushResponse(apValidationCharge());
        $this->gateway->pushResponse(['id' => 1, 'status' => 'approved']);

        $this->postJson('/api/v1/dashboard/cards', apStoreCard(['profile_token' => 'tok_2', 'payment_type_id' => 'credit_card']))
            ->assertStatus(201)
            ->assertJsonPath('data.automatic_billing', true)
            ->assertJsonMissingPath('data.mp_payment_profile_id');

        expect(UserCard::first()->mp_payment_profile_id)->toBe('prof-9');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/customers/cus_1/payment-profiles')
            && $request['payment_methods'][0]['token'] === 'tok_2'
            && $request['payment_methods'][0]['id'] === 'visa'
            && $request['payment_methods'][0]['type'] === 'credit_card');
    });

    it('still saves the card when the account cannot create profiles', function () {
        Http::fake([
            'api.mercadopago.com/v1/customers' => Http::response(['id' => 'cus_1'], 201),
            'api.mercadopago.com/v1/customers/*/cards' => Http::response(['id' => 'card_mp_1'], 201),
            'api.mercadopago.com/v1/customers/*/payment-profiles' => Http::response(['code' => 'PA_UNAUTHORIZED_RESULT_FROM_POLICIES'], 403),
        ]);
        $this->gateway->pushResponse(apValidationCharge());
        $this->gateway->pushResponse(['id' => 1, 'status' => 'approved']);

        $this->postJson('/api/v1/dashboard/cards', apStoreCard(['profile_token' => 'tok_2']))
            ->assertStatus(201)
            ->assertJsonPath('data.automatic_billing', false);

        expect(UserCard::first()->mp_payment_profile_id)->toBeNull();
    });

    it('retries a CVV-blocked renewal once the new card has a profile', function () {
        Queue::fake();
        $userPlan = apSubscription(['renewal_requires_payment' => 'cvv_required', 'renewal_failed_at' => now()]);
        Http::fake([
            'api.mercadopago.com/v1/customers' => Http::response(['id' => 'cus_1'], 201),
            'api.mercadopago.com/v1/customers/*/cards' => Http::response(['id' => 'card_mp_1'], 201),
            'api.mercadopago.com/v1/customers/*/payment-profiles' => Http::response(['id' => 'prof-9', 'status' => 'READY'], 201),
        ]);
        $this->gateway->pushResponse(apValidationCharge());
        $this->gateway->pushResponse(['id' => 1, 'status' => 'approved']);

        $this->postJson('/api/v1/dashboard/cards', apStoreCard(['profile_token' => 'tok_2']))->assertStatus(201);

        expect($userPlan->fresh()->renewal_requires_payment)->toBeNull();
        Queue::assertPushed(ProcessPlanRenewalJob::class);
    });

    it('updates its status from the profile notification', function () {
        $card = apCard();
        $card->update(['mp_payment_profile_status' => 'PENDING']);
        Http::fake(['*/v1/customers/cust-1/payment-profiles/prof-1' => Http::response(['id' => 'prof-1', 'status' => 'READY'])]);

        $ts = '1700000000';
        $hash = hash_hmac('sha256', 'id:prof-1;request-id:req-2;ts:'.$ts.';', 'automatic-secret');

        $this->withHeaders(['x-signature' => "ts={$ts},v1={$hash}", 'x-request-id' => 'req-2'])
            ->postJson('/api/v1/dashboard/checkout/webhook', ['type' => 'payment_profile.updated', 'data' => ['id' => 'prof-1']])
            ->assertOk();

        expect($card->fresh()->automatic_billing)->toBeTrue();
    });
});

describe('Refund of an automatic payment', function () {
    it('refunds the order through the Orders API', function () {
        $userPlan = apSubscription(['payment_id' => 'ORD01TEST', 'payment_amount' => 50]);
        Http::fake(['*/v1/orders/ORD01TEST/refund' => Http::response(['id' => 'ORD01TEST', 'status' => 'refunded', 'transactions' => ['refunds' => [['id' => 'REF01']]]])]);

        $refundId = app(RefundService::class)->refund($userPlan);

        expect($refundId)->toBe('REF01')
            ->and($userPlan->fresh()->refunded_at)->not->toBeNull()
            ->and($this->gateway->requests)->toBe([]);
    });

    it('sends the amount on a partial refund', function () {
        $userPlan = apSubscription(['payment_id' => 'ORD01TEST', 'payment_amount' => 50]);
        Http::fake([
            '*/v1/orders/ORD01TEST/refund' => Http::response(['id' => 'ORD01TEST', 'transactions' => ['refunds' => [['id' => 'REF02']]]]),
            '*/v1/orders/ORD01TEST' => Http::response(apOrder('processed', "rn-{$userPlan->getKey()}")),
        ]);

        app(RefundService::class)->refund($userPlan, 20.0);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/refund')
            && $request['transactions'] === [['id' => 'PAY01TEST', 'amount' => '20.00']]);
    });
});

describe('Removing a card', function () {
    beforeEach(function () {
        $this->actingAs($this->user, 'sanctum');
    });

    it('removes the card and its payment profile from the gateway', function () {
        $card = apCard();
        Http::fake([
            '*/v1/customers/cust-1/payment-profiles/prof-1' => Http::response([], 200),
            '*/v1/customers/cust-1/cards/card-1' => Http::response([], 200),
        ]);

        $this->deleteJson("/api/v1/dashboard/cards/{$card->getKey()}")->assertOk();

        expect(UserCard::find($card->getKey()))->toBeNull();
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/payment-profiles/prof-1'));
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/cards/card-1'));
    });

    it('still removes the card locally when the gateway fails', function () {
        $card = apCard();
        Http::fake(['*' => Http::response(['message' => 'boom'], 500)]);

        $this->deleteJson("/api/v1/dashboard/cards/{$card->getKey()}")->assertOk();

        expect(UserCard::find($card->getKey()))->toBeNull();
    });

    it('removes the cards from the gateway when the account is deleted', function () {
        apCard();
        Http::fake(['*' => Http::response([], 200)]);

        $this->deleteJson('/api/v1/dashboard/profile', ['password' => 'password'])->assertOk();

        expect(UserCard::count())->toBe(0);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/cards/card-1'));
    });
});

describe('Enabling automatic billing on a saved card', function () {
    beforeEach(function () {
        $this->actingAs($this->user, 'sanctum');
    });

    it('creates the profile from a CVV token', function () {
        $card = apCard(withProfile: false);
        Http::fake(['*/v1/customers/cust-1/payment-profiles' => Http::response(['id' => 'prof-7', 'status' => 'READY'], 201)]);

        $this->postJson("/api/v1/dashboard/cards/{$card->getKey()}/automatic-billing", ['profile_token' => 'tok_cvv'])
            ->assertOk()
            ->assertJsonPath('data.automatic_billing', true);

        expect($card->fresh()->mp_payment_profile_id)->toBe('prof-7');
    });

    it('answers 422 when the account cannot create profiles', function () {
        $card = apCard(withProfile: false);
        Http::fake(['*/v1/customers/cust-1/payment-profiles' => Http::response(['code' => 'PA_UNAUTHORIZED_RESULT_FROM_POLICIES'], 403)]);

        $this->postJson("/api/v1/dashboard/cards/{$card->getKey()}/automatic-billing", ['profile_token' => 'tok_cvv'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api-key::messages.automatic_billing_unavailable'));
    });

    it('does not touch another user card', function () {
        $card = apCard(withProfile: false);
        $card->update(['authentication_id' => Authentication::factory()->create()->getKey()]);

        $this->postJson("/api/v1/dashboard/cards/{$card->getKey()}/automatic-billing", ['profile_token' => 'tok_cvv'])
            ->assertNotFound();
    });

    it('is offered after a checkout that saved a new card without profile', function () {
        $this->plan->update(['is_active' => true]);
        Http::fake(['*/v1/customers/*/cards' => Http::response(['id' => 'card-mp-9'], 201)]);
        $this->gateway->pushResponse([
            'id' => 555,
            'status' => 'approved',
            'status_detail' => 'accredited',
            'transaction_amount' => 50.0,
            'payer' => ['id' => 'cust-9'],
            'card' => ['last_four_digits' => '1111', 'expiration_month' => 1, 'expiration_year' => 2031],
        ]);

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            'token' => 'brick-token',
            'payment_method_id' => 'visa',
            'payer' => ['email' => 'buyer@example.com'],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.automatic_billing_offer.mp_card_id', 'card-mp-9')
            ->assertJsonPath('data.automatic_billing_offer.last_four', '1111');
    });

    it('is not offered when the saved card already renews by itself, and the card stays the default', function () {
        $this->plan->update(['is_active' => true]);
        $card = apCard(withProfile: true);
        $this->gateway->pushResponse(['id' => 556, 'status' => 'approved', 'status_detail' => 'accredited', 'transaction_amount' => 50.0]);

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            'saved_card_id' => $card->getKey(),
            'token' => 'cvv-token',
            'payment_method_id' => 'visa',
            'payer' => ['email' => 'buyer@example.com'],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.automatic_billing_offer', null);

        // O model já estava como padrão na memória: com update() no model o
        // Eloquent não gravava nada e a conta ficava sem cartão padrão.
        expect($card->fresh()->is_default)->toBeTrue();
    });
});
