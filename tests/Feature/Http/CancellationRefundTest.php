<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Notifications\PlanCancelledNotification;
use RiseTechApps\ApiKey\Notifications\PlanRefundedNotification;
use RiseTechApps\ApiKey\Tests\Support\FakeMercadoPagoHttpClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->gateway = new FakeMercadoPagoHttpClient;
    MercadoPagoConfig::setHttpClient($this->gateway);

    config([
        'api-key.mercadopago.access_token' => 'TEST-not-a-real-token',
        'api-key.refund.window_days' => 7,
        'api-key.refund.max_usage_percent' => 50,
    ]);

    $this->user = Authentication::factory()->create();
    $this->plan = Plan::factory()->create(['price' => 100.00, 'request_limit' => 1000]);
    $this->actingAs($this->user, 'sanctum');
});

afterEach(function () {
    MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient);
});

function refundableSubscription(array $attributes = []): UserPlan
{
    return UserPlan::factory()->create(array_merge([
        'authentication_id' => test()->user->getKey(),
        'plan_id' => test()->plan->getKey(),
        'active' => true,
        'start_date' => now()->subDays(2),
        'end_date' => now()->addDays(28),
        'requests_used' => 100,
        'payment_id' => '4400111222',
        'payment_amount' => 100.00,
    ], $attributes));
}

describe('Cancelamento com direito a estorno', function () {
    it('devolve o valor e encerra o acesso na hora', function () {
        // O acesso não pode seguir até o end_date: o dinheiro voltou, então
        // manter o período seria entregá-lo de graça.
        $userPlan = refundableSubscription();
        $this->gateway->pushResponse(['id' => 9001, 'status' => 'approved']);

        $this->postJson('/api/v1/dashboard/signature/cancel')
            ->assertStatus(200)
            ->assertJsonPath('data.refunded', true)
            ->assertJsonPath('data.refunded_amount', 100)
            ->assertJsonPath('data.access_until', null);

        $fresh = $userPlan->fresh();

        expect($fresh->active)->toBeFalse()
            ->and($fresh->refunded_at)->not->toBeNull()
            ->and($fresh->refund_id)->toBe('9001')
            ->and($this->user->fresh()->hasActivePlan())->toBeFalse();
    });

    it('estorna exatamente o valor pago, nao o preco de tabela', function () {
        // Cupom e crédito de troca fazem o cobrado divergir do preço do plano.
        refundableSubscription(['payment_amount' => 42.50]);
        $this->gateway->pushResponse(['id' => 9002, 'status' => 'approved']);

        $this->postJson('/api/v1/dashboard/signature/cancel')->assertStatus(200);

        expect($this->gateway->payload()['amount'])->toEqual(42.5);
    });

    it('avisa o cliente pelo e-mail de estorno, nao pelo de cancelamento', function () {
        Notification::fake();

        refundableSubscription();
        $this->gateway->pushResponse(['id' => 9003, 'status' => 'approved']);

        $this->postJson('/api/v1/dashboard/signature/cancel')->assertStatus(200);

        Notification::assertSentTo($this->user, PlanRefundedNotification::class);
        Notification::assertNotSentTo($this->user, PlanCancelledNotification::class);
    });
});

describe('Cancelamento sem direito a estorno', function () {
    it('mantem o comportamento antigo fora da janela', function () {
        $userPlan = refundableSubscription(['start_date' => now()->subDays(10)]);

        $this->postJson('/api/v1/dashboard/signature/cancel')
            ->assertStatus(200)
            ->assertJsonPath('data.refunded', false)
            ->assertJsonPath('data.refund_refused_reason', 'window_expired');

        $fresh = $userPlan->fresh();

        // Cancelar sem estorno segue não revogando: o período pago corre até o fim.
        expect($fresh->active)->toBeTrue()
            ->and($fresh->refunded_at)->toBeNull()
            ->and($this->gateway->requests)->toBeEmpty();
    });

    it('recusa quem ja consumiu acima do teto', function () {
        refundableSubscription(['requests_used' => 900]);

        $this->postJson('/api/v1/dashboard/signature/cancel')
            ->assertStatus(200)
            ->assertJsonPath('data.refunded', false)
            ->assertJsonPath('data.refund_refused_reason', 'usage_exceeded');

        expect($this->gateway->requests)->toBeEmpty();
    });

    it('nao estorna assinatura gratuita', function () {
        refundableSubscription(['payment_id' => null, 'payment_amount' => null]);

        $this->postJson('/api/v1/dashboard/signature/cancel')
            ->assertStatus(200)
            ->assertJsonPath('data.refund_refused_reason', 'nothing_to_refund');
    });

    it('nao estorna nada com a politica desligada', function () {
        // Padrão de fábrica: quem só atualizou o pacote não passa a devolver
        // dinheiro sem pedir.
        config(['api-key.refund.window_days' => 0]);

        refundableSubscription();

        $this->postJson('/api/v1/dashboard/signature/cancel')
            ->assertStatus(200)
            ->assertJsonPath('data.refunded', false)
            ->assertJsonPath('data.refund_refused_reason', 'refund_disabled');

        expect($this->gateway->requests)->toBeEmpty();
    });
});

describe('Quando o gateway recusa o estorno', function () {
    it('mantem o cancelamento e nao revoga o acesso', function () {
        // O assinante pediu para sair e essa parte valeu. O que se perde é a
        // devolução automática, que vira tarefa do painel admin — revogar o
        // acesso sem ter devolvido o dinheiro seria o pior desfecho.
        $userPlan = refundableSubscription();
        $this->gateway->pushFailure(['message' => 'refund refused']);

        $this->postJson('/api/v1/dashboard/signature/cancel')
            ->assertStatus(200)
            ->assertJsonPath('data.refunded', false);

        $fresh = $userPlan->fresh();

        expect($fresh->cancelled_at)->not->toBeNull()
            ->and($fresh->active)->toBeTrue()
            ->and($fresh->refunded_at)->toBeNull();
    });
});

describe('Idempotencia', function () {
    it('nao estorna duas vezes num cancelamento repetido', function () {
        refundableSubscription();
        $this->gateway->pushResponse(['id' => 9004, 'status' => 'approved']);

        $this->postJson('/api/v1/dashboard/signature/cancel')->assertStatus(200);
        $this->postJson('/api/v1/dashboard/signature/cancel')->assertStatus(404);

        // Só a primeira chamada falou com o gateway; a assinatura já não está
        // mais ativa, então a segunda nem encontra o que cancelar.
        expect($this->gateway->requests)->toHaveCount(1);
    });
});
