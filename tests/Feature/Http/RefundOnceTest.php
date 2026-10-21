<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use RiseTechApps\ApiKey\Exceptions\SubscriptionAlreadyRefundedException;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Services\RefundService;
use RiseTechApps\ApiKey\Tests\Support\FakeMercadoPagoHttpClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->gateway = new FakeMercadoPagoHttpClient;
    MercadoPagoConfig::setHttpClient($this->gateway);
    config(['api-key.mercadopago.access_token' => 'TEST-not-a-real-token']);

    $this->admin = Authentication::factory()->create(['role' => 'admin']);

    $this->userPlan = UserPlan::factory()->create([
        'authentication_id' => Authentication::factory()->create()->getKey(),
        'plan_id' => Plan::factory()->create(['price' => 100.00])->getKey(),
        'payment_id' => '4400111222',
        'payment_amount' => 100.00,
    ]);
});

afterEach(function () {
    MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient);
});

describe('Um pagamento é estornado uma vez só', function () {
    it('o serviço recusa estornar de novo sem falar com o gateway', function () {
        $this->gateway->pushResponse(['id' => 987, 'status' => 'approved', 'amount' => 100.00]);

        $service = app(RefundService::class);
        $service->refund($this->userPlan, 100.00);

        $callsAfterFirst = count($this->gateway->requests);

        // Mesma instância em memória, ainda com refunded_at vazio: é o que um
        // segundo pedido concorrente teria lido antes do primeiro gravar.
        $stale = UserPlan::find($this->userPlan->getKey());
        $stale->refunded_at = null;

        expect(fn () => $service->refund($stale, 100.00))
            ->toThrow(SubscriptionAlreadyRefundedException::class)
            ->and($this->gateway->requests)->toHaveCount($callsAfterFirst);
    });

    it('o painel responde que já foi estornado no segundo clique', function () {
        $this->gateway
            ->pushResponse(['id' => 4400111222, 'status' => 'approved', 'transaction_amount' => 100.00])
            ->pushResponse(['id' => 987, 'status' => 'approved', 'amount' => 100.00]);

        $this->actingAs($this->admin, 'sanctum');
        $url = "/api/v1/dashboard/admin/refunds/{$this->userPlan->getKey()}";

        $this->postJson($url)->assertStatus(200);

        $this->postJson($url)
            ->assertStatus(422)
            ->assertJsonPath('message', __('api-key::messages.refund.already_refunded'));

        // GET do pagamento + POST do estorno, uma vez. Nada do segundo clique.
        expect($this->gateway->requests)->toHaveCount(2)
            ->and($this->userPlan->fresh()->refund_id)->toBe('987');
    });

    it('atualiza a instância recebida com o rastro do estorno', function () {
        $this->gateway->pushResponse(['id' => 987, 'status' => 'approved', 'amount' => 50.00]);

        app(RefundService::class)->refund($this->userPlan, 50.00);

        expect($this->userPlan->refunded_at)->not->toBeNull()
            ->and($this->userPlan->refund_id)->toBe('987')
            ->and($this->userPlan->active)->toBeFalse();
    });

    it('não marca nada quando o gateway falha', function () {
        $this->gateway->pushFailure(['message' => 'gateway exploded'], 500);

        expect(fn () => app(RefundService::class)->refund($this->userPlan, 100.00))
            ->toThrow(Exception::class);

        $fresh = $this->userPlan->fresh();

        expect($fresh->refunded_at)->toBeNull()
            ->and($fresh->active)->toBeTrue();
    });
});
