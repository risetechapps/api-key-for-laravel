<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use RiseTechApps\ApiKey\Gateways\EfiGateway;
use RiseTechApps\ApiKey\Gateways\GatewayRegistry;
use RiseTechApps\ApiKey\Gateways\PagarmeGateway;
use RiseTechApps\ApiKey\Gateways\PagbankGateway;
use RiseTechApps\ApiKey\Gateways\StripeGateway;
use RiseTechApps\ApiKey\Jobs\ProcessPlanRenewalJob;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\PendingPayment\PendingPayment;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Notifications\RenewalFailedNotification;
use RiseTechApps\ApiKey\Services\AutomaticPaymentService;
use RiseTechApps\ApiKey\Services\MpCustomerService;
use RiseTechApps\ApiKey\Services\PaymentSettingsService;
use RiseTechApps\ApiKey\Services\RefundService;
use RiseTechApps\ApiKey\Tests\Support\FakeMercadoPagoHttpClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    $this->mp = new FakeMercadoPagoHttpClient;
    MercadoPagoConfig::setHttpClient($this->mp);
    PaymentSettingsService::forget();
    Cache::flush();

    $this->user = Authentication::factory()->create(['cpf' => '529.982.247-25', 'cellphone' => '(21) 98765-4321', 'birth_date' => '1990-05-20']);
    $this->plan = Plan::factory()->create(['price' => 50.00, 'request_limit' => 100, 'is_active' => true]);
});

afterEach(function () {
    MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient);
});

function gwCard(string $gateway, array $attributes = []): UserCard
{
    return UserCard::create([
        'authentication_id' => test()->user->getKey(),
        'holder_name' => 'ANA',
        'last_four' => '4242',
        'brand' => 'visa',
        'expiry_month' => '12',
        'expiry_year' => '2030',
        'is_default' => true,
        'gateway' => $gateway,
        ...$attributes,
    ]);
}

function gwSubscription(array $attributes = []): UserPlan
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

function gwReference(): string
{
    return test()->user->getKey().'|'.test()->plan->getKey();
}

describe('Gateway registry', function () {
    it('tells every gateway apart by the payment id', function () {
        expect(GatewayRegistry::forPaymentId('or_abc'))->toBeInstanceOf(PagarmeGateway::class)
            ->and(GatewayRegistry::forPaymentId('pi_3Abc'))->toBeInstanceOf(StripeGateway::class)
            ->and(GatewayRegistry::forPaymentId('efi_123'))->toBeInstanceOf(EfiGateway::class)
            ->and(GatewayRegistry::forPaymentId('efipix_abc'))->toBeInstanceOf(EfiGateway::class)
            ->and(GatewayRegistry::forPaymentId('ORDE_1A2B'))->toBeInstanceOf(PagbankGateway::class)
            ->and(GatewayRegistry::forPaymentId('123456789'))->toBeNull()
            ->and(GatewayRegistry::forPaymentId('ORD01JQ4S4KY8HWQ6NA5PXB65B3D3'))->toBeNull();
    });

    it('does not take a PagBank order for a Mercado Pago order', function () {
        expect(AutomaticPaymentService::isOrderId('ORD01JQ4S4KY8HWQ6NA5PXB65B3D3'))->toBeTrue()
            ->and(AutomaticPaymentService::isOrderId('ORDE_1A2B3C'))->toBeFalse();
    });

    it('hides Pagar.me from the selector unless it is already active', function () {
        $admin = Authentication::factory()->create(['role' => 'admin']);

        $gateways = collect($this->actingAs($admin, 'sanctum')->getJson('/api/v1/dashboard/admin/payment-settings')->json('data.gateways'))->keyBy('key');

        expect($gateways['pagarme']['hidden'])->toBeTrue()
            ->and($gateways['stripe']['hidden'])->toBeFalse()
            ->and($gateways->keys()->all())->toBe(['mercadopago', 'stripe', 'efi', 'pagbank', 'pagarme']);

        config(['api-key.payment.gateway' => 'pagarme']);

        $gateways = collect($this->getJson('/api/v1/dashboard/admin/payment-settings')->json('data.gateways'))->keyBy('key');
        expect($gateways['pagarme']['hidden'])->toBeFalse();
    });

    it('filters admin payments by any gateway', function () {
        $admin = Authentication::factory()->create(['role' => 'admin']);
        gwSubscription(['payment_id' => 'pi_1', 'payment_amount' => 50]);
        gwSubscription(['payment_id' => '998877', 'payment_amount' => 50, 'active' => false]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/dashboard/admin/payments?gateway=stripe')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.gateway', 'stripe');

        $this->getJson('/api/v1/dashboard/admin/payments?gateway=mercadopago')
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.payment_id', '998877');
    });

    it('stores the Efí certificate encrypted and only says whether it exists', function () {
        $admin = Authentication::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/dashboard/admin/payment-settings', ['values' => ['efi.pix.certificate' => 'not base64!!']])
            ->assertStatus(422);

        $values = $this->putJson('/api/v1/dashboard/admin/payment-settings', ['values' => ['efi.pix.certificate' => 'data:application/x-pkcs12;base64,'.base64_encode('P12DATA')]])
            ->assertOk()
            ->json('data.values');

        expect($values['efi.pix.certificate'])->toBe(['set' => true, 'hint' => null])
            ->and(config('api-key.efi.pix.certificate'))->toBe(base64_encode('P12DATA'));
    });
});

/** PaymentIntent como a API do Stripe devolve. */
function stIntent(string $status, array $attributes = []): array
{
    return [
        'id' => 'pi_TEST1',
        'object' => 'payment_intent',
        'status' => $status,
        'amount' => 5000,
        'payment_method_types' => ['card'],
        'metadata' => ['reference' => gwReference()],
        ...$attributes,
    ];
}

describe('Stripe', function () {
    beforeEach(function () {
        config([
            'api-key.payment.gateway' => 'stripe',
            'api-key.stripe.secret_key' => 'sk_test_123',
            'api-key.stripe.publishable_key' => 'pk_test_123',
            'api-key.stripe.webhook_secret' => 'whsec_test',
            'api-key.stripe.pix.enabled' => true,
        ]);
        $this->actingAs($this->user, 'sanctum');
    });

    it('exposes only the publishable key to the browser', function () {
        $this->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.payment_gateway', 'stripe')
            ->assertJsonPath('data.payment_gateway_config', ['publishable_key' => 'pk_test_123']);
    });

    it('opens a setup intent for the signed-in user', function () {
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/setup_intents' => Http::response(['id' => 'seti_1', 'client_secret' => 'seti_1_secret_x']),
        ]);

        $this->postJson('/api/v1/dashboard/cards/setup-intent')
            ->assertOk()
            ->assertJsonPath('data.client_secret', 'seti_1_secret_x');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/setup_intents')
            && $r['usage'] === 'off_session'
            && $r['customer'] === 'cus_1'
            && $r['metadata']['api_key_user'] === (string) $this->user->getKey());
    });

    it('saves the card of a confirmed setup intent', function () {
        Http::fake(['api.stripe.com/v1/setup_intents/seti_1*' => Http::response([
            'id' => 'seti_1', 'status' => 'succeeded', 'customer' => 'cus_1', 'metadata' => ['api_key_user' => (string) $this->user->getKey()],
            'payment_method' => ['id' => 'pm_1', 'card' => ['last4' => '4242', 'brand' => 'visa', 'exp_month' => 4, 'exp_year' => 2031], 'billing_details' => ['name' => 'ANA']],
        ])]);

        $this->postJson('/api/v1/dashboard/cards', ['card_token' => 'seti_1', 'holder_name' => 'ANA'])
            ->assertStatus(201)
            ->assertJsonPath('data.automatic_billing', true);

        $card = UserCard::first();
        expect($card->gateway)->toBe('stripe')
            ->and($card->gateway_card_id)->toBe('pm_1')
            ->and($card->gateway_customer_id)->toBe('cus_1')
            ->and($card->expiry_month)->toBe('04');
    });

    it('refuses a setup intent opened for someone else', function () {
        Http::fake(['api.stripe.com/v1/setup_intents/seti_1*' => Http::response([
            'id' => 'seti_1', 'status' => 'succeeded', 'customer' => 'cus_other', 'metadata' => ['api_key_user' => 'someone-else'],
            'payment_method' => ['id' => 'pm_1', 'card' => ['last4' => '4242']],
        ])]);

        $this->postJson('/api/v1/dashboard/cards', ['card_token' => 'seti_1'])->assertStatus(422);

        expect(UserCard::count())->toBe(0);
    });

    it('charges a saved card on session at checkout', function () {
        $card = gwCard('stripe', ['gateway_customer_id' => 'cus_1', 'gateway_card_id' => 'pm_1']);
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(stIntent('succeeded'))]);

        $this->postJson('/api/v1/dashboard/checkout/process', ['plan_id' => $this->plan->getKey(), 'saved_card_id' => $card->getKey()])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        expect(UserPlan::where('payment_id', 'pi_TEST1')->where('active', true)->exists())->toBeTrue();

        Http::assertSent(fn (Request $r) => $r['off_session'] === 'false'
            && $r['payment_method'] === 'pm_1'
            && $r['amount'] === 5000
            && $r['currency'] === 'brl'
            && $r->hasHeader('Idempotency-Key'));
    });

    it('hands the 3DS challenge to the browser and settles it from the status check', function () {
        $card = gwCard('stripe', ['gateway_customer_id' => 'cus_1', 'gateway_card_id' => 'pm_1']);
        Http::fake([
            'api.stripe.com/v1/payment_intents' => Http::response(stIntent('requires_action', ['client_secret' => 'pi_TEST1_secret_y'])),
            'api.stripe.com/v1/payment_intents/pi_TEST1*' => Http::response(stIntent('succeeded')),
        ]);

        $this->postJson('/api/v1/dashboard/checkout/process', ['plan_id' => $this->plan->getKey(), 'saved_card_id' => $card->getKey()])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment_id', 'pi_TEST1')
            ->assertJsonPath('data.client_action', ['type' => 'stripe_next_action', 'client_secret' => 'pi_TEST1_secret_y']);

        $this->getJson('/api/v1/dashboard/checkout/payments/pi_TEST1')->assertJsonPath('data.status', 'approved');

        expect(UserPlan::where('payment_id', 'pi_TEST1')->where('active', true)->exists())->toBeTrue();
    });

    it('renews off session and retries a decline', function () {
        gwCard('stripe', ['gateway_customer_id' => 'cus_1', 'gateway_card_id' => 'pm_1']);
        $userPlan = gwSubscription();
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['error' => [
            'code' => 'card_declined', 'decline_code' => 'insufficient_funds',
            'payment_intent' => stIntent('requires_payment_method'),
        ]], 402)]);

        (new ProcessPlanRenewalJob($userPlan->getKey()))->handle(app(MpCustomerService::class));

        expect($userPlan->fresh()->renewal_attempts)->toBe(1);
        Notification::assertSentTo($this->user, RenewalFailedNotification::class);
        Http::assertSent(fn (Request $r) => $r['off_session'] === 'true' && str_starts_with($r['metadata']['reference'], 'renewal|'));
    });

    it('creates a PIX and settles it from a signed webhook', function () {
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/payment_intents' => Http::response(stIntent('requires_action', [
                'payment_method_types' => ['pix'],
                'next_action' => ['pix_display_qr_code' => ['data' => 'pix-copia', 'image_url_png' => 'https://qr.stripe.com/x.png']],
            ])),
            'qr.stripe.com/*' => Http::response('PNG'),
            'api.stripe.com/v1/payment_intents/pi_TEST1*' => Http::response(stIntent('succeeded', ['payment_method_types' => ['pix']])),
        ]);

        $this->postJson('/api/v1/dashboard/checkout/process', ['plan_id' => $this->plan->getKey(), 'payment_method' => 'pix'])
            ->assertOk()
            ->assertJsonPath('data.payment_id', 'pi_TEST1')
            ->assertJsonPath('data.qr_code', 'pix-copia')
            ->assertJsonPath('data.qr_code_base64', base64_encode('PNG'));

        $payload = json_encode(['type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_TEST1', 'object' => 'payment_intent']]]);
        $t = time();

        $this->call('POST', '/api/v1/dashboard/checkout/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$t},v1=bad"], $payload)
            ->assertStatus(401);

        $signature = hash_hmac('sha256', "{$t}.{$payload}", 'whsec_test');
        $this->call('POST', '/api/v1/dashboard/checkout/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$t},v1={$signature}"], $payload)
            ->assertOk();

        expect(UserPlan::where('payment_id', 'pi_TEST1')->where('payment_method', 'pix')->exists())->toBeTrue();
    });

    it('refuses a PIX above what Stripe accepts', function () {
        $this->plan->update(['price' => 5000]);

        $this->postJson('/api/v1/dashboard/checkout/process', ['plan_id' => $this->plan->getKey(), 'payment_method' => 'pix'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api-key::messages.pix_amount_out_of_range', ['min' => 'R$ 0,50', 'max' => 'R$ 3.000,00']));
    });

    it('refuses a webhook when no signing secret is configured', function () {
        config(['api-key.stripe.webhook_secret' => null]);
        Http::fake();

        $payload = json_encode(['type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_TEST1', 'object' => 'payment_intent']]]);

        $this->call('POST', '/api/v1/dashboard/checkout/webhook/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)
            ->assertStatus(401);

        Http::assertNothingSent();
    });

    it('refunds through the payment intent', function () {
        $userPlan = gwSubscription(['payment_id' => 'pi_TEST1', 'payment_amount' => 50]);
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1'])]);

        expect(app(RefundService::class)->refund($userPlan, 20.0))->toBe('re_1');

        Http::assertSent(fn (Request $r) => $r['payment_intent'] === 'pi_TEST1' && $r['amount'] === 2000);
    });
});

describe('Efí', function () {
    beforeEach(function () {
        config([
            'api-key.payment.gateway' => 'efi',
            'api-key.efi.client_id' => 'Client_Id_1',
            'api-key.efi.client_secret' => 'Client_Secret_1',
            'api-key.efi.account_id' => 'payee123',
            'api-key.efi.pix.enabled' => true,
            'api-key.efi.pix.key' => 'chave@exemplo.com',
            'api-key.efi.pix.certificate' => base64_encode("-----BEGIN CERTIFICATE-----\nAAA\n-----END CERTIFICATE-----\n"),
        ]);
        $this->user->address()->create(['zip_code' => '20040-020', 'address' => 'Rua A', 'number' => '10', 'district' => 'Centro', 'city' => 'Rio de Janeiro', 'state' => 'RJ']);
        $this->actingAs($this->user, 'sanctum');
    });

    it('saves the reusable payment token without charging', function () {
        Http::fake();

        $this->postJson('/api/v1/dashboard/cards', [
            'card_token' => str_repeat('a1', 20),
            'cpf' => '529.982.247-25',
            'holder_name' => 'ANA',
            'extra' => ['card_mask' => 'XXXXXXXXXXXX1111', 'brand' => 'visa', 'expiry_month' => '3', 'expiry_year' => '2031'],
        ])->assertStatus(201)->assertJsonPath('data.automatic_billing', true);

        expect(UserCard::first()->gateway_card_id)->toBe(str_repeat('a1', 20))
            ->and(UserCard::first()->last_four)->toBe('1111');
        Http::assertNothingSent();
    });

    it('asks for the full profile before saving a card', function () {
        $this->user->forceFill(['birth_date' => null])->save();

        $this->postJson('/api/v1/dashboard/cards', [
            'card_token' => str_repeat('a1', 20),
            'cpf' => '529.982.247-25',
            'extra' => ['card_mask' => 'XXXXXXXXXXXX1111'],
        ])->assertStatus(422)->assertJsonPath('message', __('api-key::messages.card_requires_full_profile'));
    });

    it('renews with the saved token and the full reference', function () {
        gwCard('efi', ['gateway_card_id' => str_repeat('a1', 20), 'gateway_customer_id' => '52998224725']);
        $userPlan = gwSubscription();
        Http::fake([
            'cobrancas.api.efipay.com.br/v1/authorize' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
            'cobrancas.api.efipay.com.br/v1/charge/one-step' => Http::response(['code' => 200, 'data' => ['charge_id' => 555, 'status' => 'approved', 'total' => 5000]]),
        ]);

        (new ProcessPlanRenewalJob($userPlan->getKey()))->handle(app(MpCustomerService::class));

        expect(UserPlan::where('payment_id', 'efi_555')->where('active', true)->exists())->toBeTrue();

        Http::assertSent(function (Request $r) use ($userPlan) {
            if (! str_ends_with($r->url(), '/one-step')) {
                return false;
            }
            $card = $r['payment']['credit_card'];

            return $card['payment_token'] === str_repeat('a1', 20)
                && $card['customer']['birth'] === '1990-05-20'
                && $card['billing_address']['zipcode'] === '20040020'
                && $r['items'][0]['value'] === 5000
                // `|` não passa no custom_id da Efí (HTTP 500): vai como `_`.
                && $r['metadata']['custom_id'] === "renewal_{$this->user->getKey()}_{$this->plan->getKey()}_{$userPlan->getKey()}"
                && preg_match('/^[a-zA-Z0-9_\-\s]+$/', $r['metadata']['custom_id']) === 1;
        });
    });

    it('follows the notification token to the charge', function () {
        PendingPayment::create(['authentication_id' => $this->user->getKey(), 'plan_id' => $this->plan->getKey(), 'payment_id' => 'efi_555', 'amount' => 50, 'status' => 'in_process']);
        Http::fake([
            'cobrancas.api.efipay.com.br/v1/authorize' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
            'cobrancas.api.efipay.com.br/v1/notification/abc-123-def-456' => Http::response(['data' => [['identifiers' => ['charge_id' => 555], 'status' => ['current' => 'paid']]]]),
            'cobrancas.api.efipay.com.br/v1/charge/555' => Http::response(['data' => ['charge_id' => 555, 'status' => 'paid', 'total' => 5000, 'custom_id' => str_replace('|', '_', gwReference())]]),
        ]);

        $this->post('/api/v1/dashboard/checkout/webhook/efi', ['notification' => 'abc-123-def-456'])->assertOk();

        expect(UserPlan::where('payment_id', 'efi_555')->where('active', true)->exists())->toBeTrue();
    });

    it('creates the PIX with the certificate and settles it from the status check', function () {
        Http::fake([
            'pix.api.efipay.com.br/oauth/token' => Http::response(['access_token' => 'pixtok', 'expires_in' => 3600]),
            'pix.api.efipay.com.br/v2/loc/*' => Http::response(['imagemQrcode' => 'data:image/png;base64,UE5H']),
            'pix.api.efipay.com.br/v2/cob/*' => Http::sequence()
                ->push(['txid' => 'TX1', 'status' => 'ATIVA', 'loc' => ['id' => 9], 'pixCopiaECola' => 'pix-efi', 'valor' => ['original' => '50.00'], 'calendario' => ['criacao' => now()->toIso8601String(), 'expiracao' => 1800], 'infoAdicionais' => [['nome' => 'referencia', 'valor' => gwReference()]]])
                ->push(['txid' => 'TX1', 'status' => 'CONCLUIDA', 'valor' => ['original' => '50.00'], 'calendario' => ['criacao' => now()->toIso8601String(), 'expiracao' => 1800], 'infoAdicionais' => [['nome' => 'referencia', 'valor' => gwReference()]], 'pix' => [['endToEndId' => 'E1', 'valor' => '50.00']]]),
        ]);

        $this->postJson('/api/v1/dashboard/checkout/process', ['plan_id' => $this->plan->getKey(), 'payment_method' => 'pix'])
            ->assertOk()
            ->assertJsonPath('data.payment_id', 'efipix_TX1')
            ->assertJsonPath('data.qr_code', 'pix-efi')
            ->assertJsonPath('data.qr_code_base64', 'UE5H');

        $this->getJson('/api/v1/dashboard/checkout/payments/efipix_TX1')->assertJsonPath('data.status', 'approved');

        expect(UserPlan::where('payment_id', 'efipix_TX1')->where('payment_method', 'pix')->exists())->toBeTrue();
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['chave'] === 'chave@exemplo.com' && $r['devedor']['cpf'] === '52998224725');
    });
});

it('reports a PIX key refused by Efí instead of looking the txid up', function () {
    config([
        'api-key.payment.gateway' => 'efi',
        'api-key.efi.client_id' => 'Client_Id_1',
        'api-key.efi.client_secret' => 'Client_Secret_1',
        'api-key.efi.pix.enabled' => true,
        'api-key.efi.pix.key' => 'chave@exemplo.com',
        'api-key.efi.pix.certificate' => base64_encode("-----BEGIN CERTIFICATE-----\nAAA\n-----END CERTIFICATE-----\n"),
    ]);
    Http::fake([
        'pix.api.efipay.com.br/oauth/token' => Http::response(['access_token' => 'pixtok', 'expires_in' => 3600]),
        'pix.api.efipay.com.br/v2/cob/*' => Http::response(['nome' => 'valor_invalido', 'mensagem' => 'A chave informada não faz referência à conta Efí autenticada'], 400),
    ]);

    expect(fn () => app(EfiGateway::class)->createPix($this->user, 10.0, 'x', 'ref', now()->addHour(), 'k1'))
        ->toThrow(RuntimeException::class, 'A chave informada não faz referência à conta Efí autenticada');

    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $r) => $r->method() === 'GET');
});

describe('Saved card from another gateway', function () {
    beforeEach(function () {
        config([
            'api-key.payment.gateway' => 'efi',
            'api-key.efi.client_id' => 'Client_Id_1',
            'api-key.efi.client_secret' => 'Client_Secret_1',
            'api-key.mercadopago.access_token' => 'TEST-token',
            'api-key.mercadopago.public_key' => 'TEST-pk',
        ]);
        $this->mpCard = gwCard('mercadopago', ['mp_customer_id' => 'cust-1', 'mp_card_id' => 'card-1']);
        $this->actingAs($this->user, 'sanctum');
    });

    it('is refused at checkout once the active gateway changed', function () {
        Http::fake();

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            'saved_card_id' => $this->mpCard->getKey(),
            'token' => 'cvv-token',
            'payment_method_id' => 'visa',
            'payer' => ['email' => $this->user->email],
        ])->assertStatus(422);

        expect($this->mp->requests)->toBe([])
            ->and(UserPlan::count())->toBe(0);
        Http::assertNothingSent();
    });

    it('is shown as out of use and not charged on renewal', function () {
        $userPlan = gwSubscription();
        Http::fake();

        $this->getJson('/api/v1/dashboard/cards')->assertJsonPath('data.0.usable', false);

        (new ProcessPlanRenewalJob($userPlan->getKey()))->handle(app(MpCustomerService::class));

        expect($this->mp->requests)->toBe([])
            ->and($userPlan->fresh()->renewal_requires_payment)->toBe('card_out_of_use');
    });

    it('is not accepted to pay an open overage', function () {
        $userPlan = gwSubscription(['end_date' => now()->subDay(), 'overage_requests' => 30, 'overage_amount' => 3, 'overage_billed_at' => now()]);

        $this->postJson("/api/v1/dashboard/billing/overage/{$userPlan->getKey()}/card", ['card_id' => $this->mpCard->getKey(), 'token' => 'cvv'])
            ->assertStatus(422);

        expect($this->mp->requests)->toBe([]);
    });
});

/** Pedido como a API do PagBank devolve. */
function pbOrder(string $chargeStatus = 'PAID', array $attributes = []): array
{
    return [
        'id' => 'ORDE_TEST1',
        'reference_id' => 'ref',
        'charges' => $chargeStatus === '' ? [] : [[
            'id' => 'CHAR_1',
            'status' => $chargeStatus,
            'amount' => ['value' => 5000, 'currency' => 'BRL', 'summary' => ['total' => 5000, 'paid' => 5000, 'refunded' => 0]],
            'payment_method' => ['type' => 'CREDIT_CARD', 'card' => ['brand' => 'visa']],
            'payment_response' => ['code' => '20000', 'message' => 'SUCESSO'],
        ]],
        ...$attributes,
    ];
}

describe('PagBank', function () {
    beforeEach(function () {
        config([
            'api-key.payment.gateway' => 'pagbank',
            'api-key.pagbank.token' => 'pb-token',
            'api-key.pagbank.pix.enabled' => true,
        ]);
        $this->actingAs($this->user, 'sanctum');
    });

    it('fetches the public key once for the browser', function () {
        Http::fake(['api.pagseguro.com/public-keys/card' => Http::response(['public_key' => 'MIIB-key'])]);

        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.payment_gateway_config', ['public_key' => 'MIIB-key']);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.payment_gateway_config', ['public_key' => 'MIIB-key']);

        Http::assertSentCount(1);
    });

    it('saves the encrypted card and opens the recurrence with the CVV at checkout', function () {
        Http::fake([
            'api.pagseguro.com/tokens/cards' => Http::response(['id' => 'CARD_1', 'last_digits' => '1111', 'brand' => 'mastercard', 'exp_month' => 3, 'exp_year' => 2031]),
            'api.pagseguro.com/orders' => Http::response(pbOrder()),
        ]);

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            'card_token' => str_repeat('enc', 120),
            'cpf' => '529.982.247-25',
            'holder_name' => 'ANA',
            'extra' => ['security_code' => '123'],
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        expect(UserPlan::where('payment_id', 'ORDE_TEST1')->where('active', true)->exists())->toBeTrue()
            ->and(UserCard::first()->gateway_first_charge_id)->toBe('CHAR_1');

        Http::assertSent(function (Request $r) {
            $charge = $r['charges'][0] ?? null;

            return str_ends_with($r->url(), '/orders')
                && $charge['payment_method']['card'] === ['id' => 'CARD_1', 'security_code' => '123']
                && $charge['recurring'] === ['type' => 'INITIAL']
                && strlen($r['reference_id']) <= 64
                && $r['customer']['tax_id'] === '52998224725';
        });
    });

    it('renews as a subsequent charge without the CVV', function () {
        gwCard('pagbank', ['gateway_card_id' => 'CARD_1', 'gateway_customer_id' => '52998224725', 'gateway_first_charge_id' => 'CHAR_0']);
        $userPlan = gwSubscription();
        Http::fake(['api.pagseguro.com/orders' => Http::response(pbOrder('PAID', ['id' => 'ORDE_RENEW']))]);

        (new ProcessPlanRenewalJob($userPlan->getKey()))->handle(app(MpCustomerService::class));

        expect(UserPlan::where('payment_id', 'ORDE_RENEW')->where('active', true)->exists())->toBeTrue();

        Http::assertSent(fn (Request $r) => $r['charges'][0]['recurring'] === ['type' => 'SUBSEQUENT']
            && $r['charges'][0]['payment_method']['card'] === ['id' => 'CARD_1']
            && $r['reference_id'] === "rn-{$userPlan->getKey()}");
    });

    it('settles a PIX whose reference did not fit, from the signed notification', function () {
        Http::fake([
            'api.pagseguro.com/orders' => Http::response(pbOrder('', ['qr_codes' => [['id' => 'QRCO_1', 'text' => 'pix-pb', 'expiration_date' => now()->addHour()->toIso8601String(), 'amount' => ['value' => 5000], 'links' => []]]])),
            'api.pagseguro.com/orders/ORDE_TEST1' => Http::response(pbOrder('PAID', ['reference_id' => 'hashed-ref'])),
        ]);

        $this->postJson('/api/v1/dashboard/checkout/process', ['plan_id' => $this->plan->getKey(), 'payment_method' => 'pix'])
            ->assertOk()
            ->assertJsonPath('data.payment_id', 'ORDE_TEST1')
            ->assertJsonPath('data.qr_code', 'pix-pb');

        $payload = json_encode(pbOrder('PAID'));

        $this->call('POST', '/api/v1/dashboard/checkout/webhook/pagbank', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_AUTHENTICITY_TOKEN' => 'forged'], $payload)
            ->assertStatus(401);

        $this->call('POST', '/api/v1/dashboard/checkout/webhook/pagbank', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_AUTHENTICITY_TOKEN' => hash('sha256', "pb-token-{$payload}")], $payload)
            ->assertOk();

        expect(UserPlan::where('payment_id', 'ORDE_TEST1')->where('active', true)->exists())->toBeTrue()
            ->and(PendingPayment::where('payment_id', 'ORDE_TEST1')->value('outcome'))->toBe('approved');
    });

    it('does not take an overage PIX for a new subscription', function () {
        $userPlan = gwSubscription(['overage_payment_id' => 'ORDE_TEST1', 'overage_amount' => 7.5]);
        PendingPayment::create(['authentication_id' => $this->user->getKey(), 'plan_id' => $this->plan->getKey(), 'payment_id' => 'ORDE_TEST1', 'amount' => 7.5, 'status' => 'pending']);
        Http::fake(['api.pagseguro.com/orders/ORDE_TEST1' => Http::response(pbOrder('PAID', ['reference_id' => 'hashed-ref']))]);

        $payload = json_encode(['id' => 'ORDE_TEST1']);
        $this->call('POST', '/api/v1/dashboard/checkout/webhook/pagbank', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_AUTHENTICITY_TOKEN' => hash('sha256', "pb-token-{$payload}")], $payload)
            ->assertOk();

        expect(UserPlan::count())->toBe(1)
            ->and($userPlan->fresh()->overage_paid_at)->not->toBeNull();
    });

    it('refunds the paid charge of the order', function () {
        $userPlan = gwSubscription(['payment_id' => 'ORDE_TEST1', 'payment_amount' => 50]);
        Http::fake([
            'api.pagseguro.com/orders/ORDE_TEST1' => Http::response(pbOrder()),
            'api.pagseguro.com/charges/CHAR_1/cancel' => Http::response(['id' => 'CHAR_1', 'status' => 'CANCELED']),
        ]);

        expect(app(RefundService::class)->refund($userPlan))->toBe('CHAR_1');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/cancel') && $r['amount'] === ['value' => 5000]);
    });
});

describe('Plan switch credit', function () {
    it('records only the credit that the purchase used', function () {
        gwSubscription(['start_date' => now(), 'end_date' => now()->addDays(30), 'payment_id' => 'pi_OLD', 'payment_amount' => 50]);
        $cheaper = Plan::factory()->create(['price' => 30.00, 'request_limit' => 100, 'is_active' => true]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/dashboard/checkout/process', ['plan_id' => $cheaper->getKey()])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $new = UserPlan::where('plan_id', $cheaper->getKey())->firstOrFail();

        expect((float) $new->credit_applied)->toBe(30.0)
            ->and((float) $new->payment_amount)->toBe(0.0)
            // E o novo período vale o que custou: R$ 30,00, não R$ 50,00.
            ->and($new->fresh()->paidValue())->toBe(30.0);
    });

    it('gives no switch credit for a plan granted by the admin', function () {
        $admin = Authentication::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/dashboard/admin/users/{$this->user->getKey()}/plan", ['plan_id' => $this->plan->getKey()])
            ->assertSuccessful();

        expect(UserPlan::where('authentication_id', $this->user->getKey())->where('active', true)->firstOrFail()->unusedCredit())->toBe(0.0);
    });
});

describe('Messages shown to the customer', function () {
    it('never shows a technical gateway error at checkout', function () {
        config([
            'api-key.payment.gateway' => 'efi',
            'api-key.efi.client_id' => 'Client_Id_1',
            'api-key.efi.client_secret' => 'Client_Secret_1',
        ]);
        $this->user->address()->create(['zip_code' => '20040-020', 'address' => 'Rua A', 'number' => '10', 'district' => 'Centro', 'city' => 'Rio de Janeiro', 'state' => 'RJ']);
        $card = gwCard('efi', ['gateway_card_id' => str_repeat('a1', 20), 'gateway_customer_id' => '52998224725']);
        Http::fake([
            'cobrancas.api.efipay.com.br/v1/authorize' => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
            'cobrancas.api.efipay.com.br/v1/charge/one-step' => Http::response(['code' => 3500034, 'error' => 'validation_error'], 500),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/dashboard/checkout/process', ['plan_id' => $this->plan->getKey(), 'saved_card_id' => $card->getKey()])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api-key::messages.error_processing_payment'));
    });

    it('still shows the messages written for the customer', function () {
        config(['api-key.payment.gateway' => 'stripe', 'api-key.stripe.secret_key' => 'sk_test_1', 'api-key.stripe.pix.enabled' => true]);
        $this->plan->update(['price' => 5000]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/dashboard/checkout/process', ['plan_id' => $this->plan->getKey(), 'payment_method' => 'pix'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api-key::messages.pix_amount_out_of_range', ['min' => 'R$ 0,50', 'max' => 'R$ 3.000,00']));
    });
});
