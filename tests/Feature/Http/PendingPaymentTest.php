<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use RiseTechApps\ApiKey\Jobs\ProcessPlanRenewalJob;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Coupon\Coupon;
use RiseTechApps\ApiKey\Models\PendingPayment\PendingPayment;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Notifications\PaymentRejectedNotification;
use RiseTechApps\ApiKey\Services\MpCustomerService;
use RiseTechApps\ApiKey\Tests\Support\FakeMercadoPagoHttpClient;

uses(RefreshDatabase::class);

const PENDING_WEBHOOK_SECRET = 'webhook-secret-for-tests';

beforeEach(function () {
    $this->gateway = new FakeMercadoPagoHttpClient;
    MercadoPagoConfig::setHttpClient($this->gateway);

    config([
        'api-key.mercadopago.access_token' => 'TEST-not-a-real-token',
        'api-key.mercadopago.webhook_secret' => PENDING_WEBHOOK_SECRET,
    ]);

    $this->user = Authentication::factory()->create();
    $this->plan = Plan::factory()->create(['price' => 100.00]);
});

afterEach(function () {
    MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient);
});

function pendingSignedHeaders(string $dataId): array
{
    $ts = '1700000000';
    $requestId = 'req-pending';
    $hash = hash_hmac('sha256', "id:{$dataId};request-id:{$requestId};ts:{$ts};", PENDING_WEBHOOK_SECRET);

    return ['x-signature' => "ts={$ts},v1={$hash}", 'x-request-id' => $requestId];
}

function notifyPendingPayment(string $dataId): TestResponse
{
    return test()->postJson(
        '/api/v1/dashboard/checkout/webhook',
        ['type' => 'payment', 'data' => ['id' => $dataId]],
        pendingSignedHeaders($dataId)
    );
}

describe('Compra que fica em analise', function () {
    it('registra a espera com o cupom reservado', function () {
        // Sem este registro, uma recusa posterior nao teria a quem se referir: o
        // comprador nao seria avisado e a vaga do cupom ficaria queimada.
        $coupon = Coupon::factory()->create(['type' => 'percentage', 'value' => 10, 'max_uses' => 5]);

        $this->actingAs($this->user, 'sanctum');
        $this->gateway->pushResponse([
            'id' => 5001,
            'status' => 'in_process',
            'status_detail' => 'pending_review_manual',
            'transaction_amount' => 90.00,
        ]);

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            'coupon_code' => $coupon->code,
            'token' => 'tok_1',
            'payment_method_id' => 'visa',
            'payer' => ['email' => 'buyer@example.com'],
        ])->assertStatus(200)->assertJsonPath('data.status', 'pending');

        $pending = PendingPayment::first();

        expect($pending)->not->toBeNull()
            ->and($pending->payment_id)->toBe('5001')
            ->and($pending->authentication_id)->toBe($this->user->getKey())
            ->and($pending->coupon_id)->toBe($coupon->getKey())
            ->and($pending->isSettled())->toBeFalse()
            // A reserva do cupom segue tomada: o pagamento ainda pode virar venda.
            ->and($coupon->fresh()->uses)->toBe(1)
            ->and(UserPlan::count())->toBe(0);
    });
});

describe('Analise que termina em recusa', function () {
    it('avisa o comprador e devolve a vaga do cupom', function () {
        Notification::fake();

        $coupon = Coupon::factory()->create(['max_uses' => 1, 'uses' => 1]);

        PendingPayment::create([
            'authentication_id' => $this->user->getKey(),
            'plan_id' => $this->plan->getKey(),
            'payment_id' => '5002',
            'amount' => 100.00,
            'coupon_id' => $coupon->getKey(),
            'status' => 'in_process',
        ]);

        $this->gateway->pushResponse([
            'id' => 5002,
            'status' => 'rejected',
            'status_detail' => 'cc_rejected_high_risk',
            'external_reference' => $this->user->getKey().'|'.$this->plan->getKey(),
        ]);

        notifyPendingPayment('5002')->assertStatus(200);

        $pending = PendingPayment::first();

        expect($pending->isSettled())->toBeTrue()
            ->and($pending->outcome)->toBe('rejected')
            // A vaga volta: ninguem comprou.
            ->and($coupon->fresh()->uses)->toBe(0)
            ->and(UserPlan::count())->toBe(0);

        // Fecha a promessa do checkout: "voce sera notificado em breve".
        Notification::assertSentTo($this->user, PaymentRejectedNotification::class);
    });

    it('nao devolve a vaga duas vezes numa reentrega', function () {
        // O Mercado Pago reentrega ate receber 200, entao a mesma recusa chega
        // mais de uma vez com frequencia.
        Notification::fake();

        $coupon = Coupon::factory()->create(['max_uses' => 5, 'uses' => 1]);

        PendingPayment::create([
            'authentication_id' => $this->user->getKey(),
            'plan_id' => $this->plan->getKey(),
            'payment_id' => '5003',
            'amount' => 100.00,
            'coupon_id' => $coupon->getKey(),
            'status' => 'in_process',
        ]);

        $rejected = [
            'id' => 5003,
            'status' => 'rejected',
            'status_detail' => 'cc_rejected_high_risk',
            'external_reference' => $this->user->getKey().'|'.$this->plan->getKey(),
        ];

        $this->gateway->pushResponse($rejected)->pushResponse($rejected);

        notifyPendingPayment('5003')->assertStatus(200);
        notifyPendingPayment('5003')->assertStatus(200);

        expect($coupon->fresh()->uses)->toBe(0);

        Notification::assertSentToTimes($this->user, PaymentRejectedNotification::class, 1);
    });

    it('ignora recusa de um pagamento que nunca ficou pendente', function () {
        // Recusa imediata o comprador ja viu na resposta do checkout; nao ha
        // cupom preso nem promessa a cumprir.
        Notification::fake();

        $this->gateway->pushResponse([
            'id' => 5004,
            'status' => 'rejected',
            'status_detail' => 'cc_rejected_call_for_authorize',
            'external_reference' => $this->user->getKey().'|'.$this->plan->getKey(),
        ]);

        notifyPendingPayment('5004')->assertStatus(200);

        Notification::assertNothingSent();
    });
});

describe('Analise que termina em aprovacao', function () {
    it('assina o plano e fecha a espera', function () {
        PendingPayment::create([
            'authentication_id' => $this->user->getKey(),
            'plan_id' => $this->plan->getKey(),
            'payment_id' => '5005',
            'amount' => 100.00,
            'status' => 'in_process',
        ]);

        $this->gateway->pushResponse([
            'id' => 5005,
            'status' => 'approved',
            'transaction_amount' => 100.00,
            'external_reference' => $this->user->getKey().'|'.$this->plan->getKey(),
        ]);

        notifyPendingPayment('5005')->assertStatus(200);

        $pending = PendingPayment::first();

        expect($pending->outcome)->toBe('approved')
            ->and($pending->isSettled())->toBeTrue()
            ->and($this->user->fresh()->hasActivePlan())->toBeTrue();
    });
});

describe('Renovacao que fica em analise', function () {
    it('registra a espera para a reconciliacao alcancar', function () {
        // Sem o registro, uma renovacao aprovada cujo webhook nao chegue deixaria
        // o assinante pagando sem renovar, e ninguem perceberia: o job ja
        // terminou e o log diz "not approved", que naquele instante era verdade.
        $userPlan = UserPlan::factory()->create([
            'authentication_id' => $this->user->getKey(),
            'plan_id' => $this->plan->getKey(),
            'active' => true,
            'start_date' => now()->subDays(30),
            'end_date' => now(),
        ]);

        UserCard::create([
            'authentication_id' => $this->user->getKey(),
            'holder_name' => 'Fulano', 'last_four' => '4321',
            'brand' => 'visa', 'expiry_month' => '08', 'expiry_year' => '2030',
            'mp_customer_id' => 'cus_1', 'mp_card_id' => 'card_1', 'is_default' => true,
        ]);

        // Tokenizacao recorrente e depois a cobranca, que volta em analise.
        Http::fake([
            'api.mercadopago.com/v1/card_tokens' => Http::response(['id' => 'tok_recur'], 201),
        ]);

        $this->gateway->pushResponse([
            'id' => 6001,
            'status' => 'in_process',
            'status_detail' => 'pending_review_manual',
            'transaction_amount' => 100.00,
        ]);

        new ProcessPlanRenewalJob($userPlan->getKey())
            ->handle(app(MpCustomerService::class));

        $pending = PendingPayment::first();

        expect($pending)->not->toBeNull()
            ->and($pending->payment_id)->toBe('6001')
            ->and($pending->coupon_id)->toBeNull()
            ->and($pending->isSettled())->toBeFalse();
    });

    it('avisa que a assinatura atual continua ativa quando a renovacao e recusada', function () {
        // Texto diferente do da compra nova: aqui a assinatura existe e apenas
        // nao vai continuar. Dizer que "nao chegou a ser ativada" assustaria
        // quem ainda esta usando o servico.
        Notification::fake();

        PendingPayment::create([
            'authentication_id' => $this->user->getKey(),
            'plan_id' => $this->plan->getKey(),
            'payment_id' => '6002',
            'amount' => 100.00,
            'status' => 'in_process',
        ]);

        $this->gateway->pushResponse([
            'id' => 6002,
            'status' => 'rejected',
            'status_detail' => 'cc_rejected_insufficient_amount',
            'external_reference' => 'renewal|'.$this->user->getKey().'|'.$this->plan->getKey().'|algum-user-plan',
        ]);

        notifyPendingPayment('6002')->assertStatus(200);

        Notification::assertSentTo(
            $this->user,
            PaymentRejectedNotification::class,
            fn (PaymentRejectedNotification $n) => $n->isRenewal === true
        );
    });

    it('trata compra nova como compra nova', function () {
        Notification::fake();

        PendingPayment::create([
            'authentication_id' => $this->user->getKey(),
            'plan_id' => $this->plan->getKey(),
            'payment_id' => '6003',
            'amount' => 100.00,
            'status' => 'in_process',
        ]);

        $this->gateway->pushResponse([
            'id' => 6003,
            'status' => 'rejected',
            'status_detail' => 'cc_rejected_insufficient_amount',
            'external_reference' => $this->user->getKey().'|'.$this->plan->getKey(),
        ]);

        notifyPendingPayment('6003')->assertStatus(200);

        Notification::assertSentTo(
            $this->user,
            PaymentRejectedNotification::class,
            fn (PaymentRejectedNotification $n) => $n->isRenewal === false
        );
    });
});

describe('Reconciliacao quando o webhook nao chega', function () {
    it('nao mexe numa espera recente', function () {
        // Espera nova ainda pode receber o webhook; consultar agora so correria
        // contra ele.
        PendingPayment::create([
            'authentication_id' => $this->user->getKey(),
            'plan_id' => $this->plan->getKey(),
            'payment_id' => '5006',
            'amount' => 100.00,
            'status' => 'in_process',
        ]);

        $this->artisan('api-key:reconcile-payments')
            ->expectsOutputToContain('No pending payments to reconcile.')
            ->assertSuccessful();

        expect($this->gateway->requests)->toBeEmpty();
    });

    it('aplica o mesmo desfecho do webhook numa espera antiga', function () {
        Notification::fake();

        $pending = PendingPayment::create([
            'authentication_id' => $this->user->getKey(),
            'plan_id' => $this->plan->getKey(),
            'payment_id' => '5007',
            'amount' => 100.00,
            'status' => 'in_process',
        ]);

        $pending->forceFill(['created_at' => now()->subHour()])->saveQuietly();

        $this->gateway->pushResponse([
            'id' => 5007,
            'status' => 'rejected',
            'status_detail' => 'cc_rejected_high_risk',
            'external_reference' => $this->user->getKey().'|'.$this->plan->getKey(),
        ]);

        $this->artisan('api-key:reconcile-payments')->assertSuccessful();

        expect($pending->fresh()->outcome)->toBe('rejected');

        Notification::assertSentTo($this->user, PaymentRejectedNotification::class);
    });

    it('deixa em paz o que o gateway ainda analisa', function () {
        $pending = PendingPayment::create([
            'authentication_id' => $this->user->getKey(),
            'plan_id' => $this->plan->getKey(),
            'payment_id' => '5008',
            'amount' => 100.00,
            'status' => 'in_process',
        ]);

        $pending->forceFill(['created_at' => now()->subHour()])->saveQuietly();

        $this->gateway->pushResponse(['id' => 5008, 'status' => 'in_process']);

        $this->artisan('api-key:reconcile-payments')
            ->expectsOutputToContain('1 still under review')
            ->assertSuccessful();

        expect($pending->fresh()->isSettled())->toBeFalse();
    });
});
