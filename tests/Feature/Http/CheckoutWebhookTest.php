<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Tests\Support\FakeMercadoPagoHttpClient;

uses(RefreshDatabase::class);

const WEBHOOK_SECRET = 'webhook-secret-for-tests';

beforeEach(function () {
    $this->gateway = new FakeMercadoPagoHttpClient;
    MercadoPagoConfig::setHttpClient($this->gateway);

    config([
        'api-key.mercadopago.access_token' => 'TEST-not-a-real-token',
        'api-key.mercadopago.webhook_secret' => WEBHOOK_SECRET,
    ]);

    $this->user = Authentication::factory()->create();
    $this->plan = Plan::factory()->create(['price' => 100.00]);
});

afterEach(function () {
    MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient);
});

/**
 * Assina a notificação como o Mercado Pago assina: HMAC-SHA256 sobre
 * `id:<data_id>;request-id:<x-request-id>;ts:<ts>;`.
 */
function signedHeaders(string $dataId, string $requestId = 'req-1', string $ts = '1700000000'): array
{
    $hash = hash_hmac('sha256', "id:{$dataId};request-id:{$requestId};ts:{$ts};", WEBHOOK_SECRET);

    return [
        'x-signature' => "ts={$ts},v1={$hash}",
        'x-request-id' => $requestId,
    ];
}

/** Notifica o pacote sobre o pagamento `$dataId`, com assinatura válida. */
function notifyPayment(string $dataId, array $body = []): TestResponse
{
    return test()->postJson(
        '/api/v1/dashboard/checkout/webhook',
        array_merge(['type' => 'payment', 'data' => ['id' => $dataId]], $body),
        signedHeaders($dataId)
    );
}

describe('Webhook signature', function () {
    it('rejects the call when no secret is configured', function () {
        // Fail closed: sem segredo, o endpoint aceitaria de qualquer um a
        // afirmação de que um pagamento aconteceu.
        config(['api-key.mercadopago.webhook_secret' => null]);

        notifyPayment('1')->assertStatus(400);

        expect($this->gateway->requests)->toBeEmpty();
    });

    it('rejects a forged signature', function () {
        $this->postJson('/api/v1/dashboard/checkout/webhook', [
            'type' => 'payment',
            'data' => ['id' => '1'],
        ], [
            'x-signature' => 'ts=1700000000,v1=deadbeef',
            'x-request-id' => 'req-1',
        ])->assertStatus(400);

        expect($this->gateway->requests)->toBeEmpty();
    });

    it('rejects a signature computed over a different payment id', function () {
        // A assinatura cobre o data_id, então reaproveitar uma notificação
        // legítima para confirmar outro pagamento não pode funcionar.
        $this->postJson('/api/v1/dashboard/checkout/webhook', [
            'type' => 'payment',
            'data' => ['id' => '999'],
        ], signedHeaders('1'))->assertStatus(400);

        expect($this->gateway->requests)->toBeEmpty();
    });

    it('rejects a missing signature header', function () {
        $this->postJson('/api/v1/dashboard/checkout/webhook', [
            'type' => 'payment',
            'data' => ['id' => '1'],
        ])->assertStatus(400);
    });
});

describe('Notificacao IPN, sem assinatura', function () {
    /**
     * Formato real recebido em producao apos o pagamento passar a carregar
     * notification_url: query com topic e id, corpo com resource e topic, e
     * nenhum header de assinatura. O IPN nao tem HMAC por definicao.
     */
    function ipnNotification(string $paymentId): TestResponse
    {
        return test()->postJson(
            "/api/v1/dashboard/checkout/webhook?id={$paymentId}&topic=payment",
            ['resource' => $paymentId, 'topic' => 'payment', 'id' => $paymentId]
        );
    }

    it('aceita e liquida o pagamento', function () {
        // Antes respondia 400 "Assinatura invalida", e o gateway reentregava
        // para sempre porque nunca recebia 200.
        $this->gateway->pushResponse([
            'id' => 170917444983,
            'status' => 'approved',
            'external_reference' => $this->user->getKey().'|'.$this->plan->getKey(),
            'transaction_amount' => 100.00,
        ]);

        ipnNotification('170917444983')->assertStatus(200);

        expect($this->user->fresh()->hasActivePlan())->toBeTrue();
    });

    it('ignora topico que nao e pagamento', function () {
        test()->postJson(
            '/api/v1/dashboard/checkout/webhook?id=1&topic=merchant_order',
            ['resource' => '1', 'topic' => 'merchant_order']
        )->assertStatus(200);

        expect($this->gateway->requests)->toBeEmpty();
    });

    it('recusa requisicao sem assinatura que nao tem a forma de IPN', function () {
        // Sem isto o endpoint aceitaria qualquer corpo JSON sem assinatura, o
        // que anularia a validacao do webhook assinado.
        $this->postJson('/api/v1/dashboard/checkout/webhook', [
            'type' => 'payment',
            'data' => ['id' => '1'],
        ])->assertStatus(400);

        expect($this->gateway->requests)->toBeEmpty();
    });

    it('recusa IPN quando o operador desliga o formato', function () {
        config(['api-key.mercadopago.accept_ipn' => false]);

        ipnNotification('170917444983')->assertStatus(400);

        expect($this->gateway->requests)->toBeEmpty();
    });

    it('nao aceita assinatura invalida so porque ha forma de IPN', function () {
        // Assinatura presente e errada continua sendo recusa: a presenca do
        // header e o que escolhe o caminho da validacao.
        test()->postJson(
            '/api/v1/dashboard/checkout/webhook?id=1&topic=payment',
            ['resource' => '1', 'topic' => 'payment'],
            ['x-signature' => 'ts=1,v1=deadbeef', 'x-request-id' => 'req-1']
        )->assertStatus(400);

        expect($this->gateway->requests)->toBeEmpty();
    });
});

describe('Webhook routing', function () {
    it('ignores notifications that are not about a payment', function () {
        // O Mercado Pago manda merchant_order, plan, subscription… Consultar a API
        // de pagamento para cada uma seria chamada desperdiçada.
        $this->postJson('/api/v1/dashboard/checkout/webhook', [
            'type' => 'merchant_order',
            'data' => ['id' => '1'],
        ], signedHeaders('1'))->assertStatus(200);

        expect($this->gateway->requests)->toBeEmpty();
    });

    it('ignores a payment that was not approved', function () {
        $this->gateway->pushResponse([
            'id' => 1,
            'status' => 'rejected',
            'external_reference' => $this->user->getKey().'|'.$this->plan->getKey(),
        ]);

        notifyPayment('1')->assertStatus(200);

        expect(UserPlan::count())->toBe(0);
    });

    it('ignores an external_reference it does not recognise', function () {
        $this->gateway->pushResponse([
            'id' => 1,
            'status' => 'approved',
            'external_reference' => 'lixo-sem-separador',
            'transaction_amount' => 100.00,
        ]);

        notifyPayment('1')->assertStatus(200);

        expect(UserPlan::count())->toBe(0);
    });

    it('ignores the card validation charge', function () {
        // Validação de cartão cobra um valor simbólico só para tokenizar; não é
        // compra de plano e não pode assinar ninguém.
        $this->gateway->pushResponse([
            'id' => 1,
            'status' => 'approved',
            'external_reference' => $this->user->getKey().'|card_validation',
            'transaction_amount' => 1.00,
        ]);

        notifyPayment('1')->assertStatus(200);

        expect(UserPlan::count())->toBe(0);
    });
});

describe('Webhook settling a payment', function () {
    it('subscribes the buyer and records the payment trail', function () {
        $this->gateway->pushResponse([
            'id' => 555000111,
            'status' => 'approved',
            'external_reference' => $this->user->getKey().'|'.$this->plan->getKey(),
            'transaction_amount' => 89.90,
        ]);

        notifyPayment('555000111')->assertStatus(200);

        $userPlan = UserPlan::where('authentication_id', $this->user->getKey())->first();

        // Sem payment_id/payment_amount a assinatura confirmada por webhook ficava
        // sem rastro de pagamento e invisível para a tela de estorno, que filtra
        // justamente por payment_id.
        expect($userPlan)->not->toBeNull()
            ->and($userPlan->plan_id)->toBe($this->plan->getKey())
            ->and((string) $userPlan->payment_id)->toBe('555000111')
            ->and((float) $userPlan->payment_amount)->toBe(89.90)
            ->and($this->user->fresh()->hasActivePlan())->toBeTrue();
    });

    it('settles a renewal, whose reference carries a prefix', function () {
        // "renewal|<user>|<plan>|<userPlan>". O parser antigo sempre partia em
        // duas peças, então lia user_id = "renewal" e descartava em silêncio todo
        // webhook de renovação.
        $userPlan = UserPlan::factory()->create([
            'authentication_id' => $this->user->getKey(),
            'plan_id' => $this->plan->getKey(),
            'active' => true,
            'start_date' => now()->subDays(30),
            'end_date' => now()->subDay(),
        ]);

        $this->gateway->pushResponse([
            'id' => 777,
            'status' => 'approved',
            'external_reference' => 'renewal|'.$this->user->getKey().'|'.$this->plan->getKey().'|'.$userPlan->getKey(),
            'transaction_amount' => 100.00,
        ]);

        notifyPayment('777')->assertStatus(200);

        $renewed = UserPlan::where('authentication_id', $this->user->getKey())
            ->where('payment_id', '777')
            ->first();

        expect($renewed)->not->toBeNull()
            ->and($this->user->fresh()->hasActivePlan())->toBeTrue();
    });

    it('delivers a plan that was taken off sale after the purchase', function () {
        // Ao contrário do checkout, o webhook resolve por findById: o dinheiro já
        // trocou de mãos, e tirar o plano de venda no meio do caminho não pode
        // deixar o comprador sem o que pagou.
        $offSale = Plan::factory()->inactive()->create(['price' => 50.00]);

        $this->gateway->pushResponse([
            'id' => 888,
            'status' => 'approved',
            'external_reference' => $this->user->getKey().'|'.$offSale->getKey(),
            'transaction_amount' => 50.00,
        ]);

        notifyPayment('888')->assertStatus(200);

        expect(UserPlan::where('plan_id', $offSale->getKey())->exists())->toBeTrue();
    });

    it('does not subscribe twice when the same notification arrives again', function () {
        // O Mercado Pago reentrega até receber 200, então a mesma notificação
        // chega mais de uma vez com frequência.
        $payment = [
            'id' => 999,
            'status' => 'approved',
            'external_reference' => $this->user->getKey().'|'.$this->plan->getKey(),
            'transaction_amount' => 100.00,
        ];

        $this->gateway->pushResponse($payment)->pushResponse($payment);

        notifyPayment('999')->assertStatus(200);
        notifyPayment('999')->assertStatus(200);

        expect(UserPlan::where('authentication_id', $this->user->getKey())->count())->toBe(1);
    });

    it('ignores a payment for a user that no longer exists', function () {
        $this->gateway->pushResponse([
            'id' => 1,
            'status' => 'approved',
            'external_reference' => Str::uuid()->toString().'|'.$this->plan->getKey(),
            'transaction_amount' => 100.00,
        ]);

        notifyPayment('1')->assertStatus(200);

        expect(UserPlan::count())->toBe(0);
    });
});
