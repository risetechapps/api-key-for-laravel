<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Tests\Support\FakeMercadoPagoHttpClient;
use RiseTechApps\Monitoring\Loggly\Loggly;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->gateway = new FakeMercadoPagoHttpClient;
    MercadoPagoConfig::setHttpClient($this->gateway);
    config(['api-key.mercadopago.access_token' => 'TEST-not-a-real-token']);

    $this->admin = Authentication::factory()->create(['role' => 'admin']);
    $this->plan = Plan::factory()->create(['price' => 100.00]);
});

afterEach(function () {
    MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient);
});

/** Assinatura paga, que é a única que a tela de estorno enxerga. */
function paidSubscription(array $attributes = []): UserPlan
{
    return UserPlan::factory()->create(array_merge([
        'authentication_id' => Authentication::factory()->create()->getKey(),
        'plan_id' => test()->plan->getKey(),
        'active' => true,
        'start_date' => now()->subDays(2),
        'end_date' => now()->addDays(28),
        'payment_id' => '4400111222',
        'payment_amount' => 100.00,
    ], $attributes));
}

describe('Admin area access', function () {
    it('refuses a signed-in user without the admin role', function () {
        $this->actingAs(Authentication::factory()->create(['role' => 'user']), 'sanctum');

        $this->getJson('/api/v1/dashboard/admin/users')->assertStatus(403);
        $this->getJson('/api/v1/dashboard/admin/refunds')->assertStatus(403);
        $this->getJson('/api/v1/dashboard/admin/plans')->assertStatus(403);
    });

    it('refuses an anonymous caller', function () {
        $this->getJson('/api/v1/dashboard/admin/users')->assertStatus(401);
    });

    it('refuses to refund without the admin role', function () {
        // A rota que move dinheiro é a que mais importa nesta checagem.
        $userPlan = paidSubscription();
        $this->actingAs(Authentication::factory()->create(['role' => 'user']), 'sanctum');

        $this->postJson("/api/v1/dashboard/admin/refunds/{$userPlan->getKey()}")->assertStatus(403);

        expect($this->gateway->requests)->toBeEmpty()
            ->and($userPlan->fresh()->active)->toBeTrue();
    });
});

describe('Refund listing', function () {
    it('lists only subscriptions that carry a payment', function () {
        // Sem payment_id não há o que estornar; listar essas assinaturas daria ao
        // operador um botão que só pode falhar.
        $paid = paidSubscription();
        UserPlan::factory()->create([
            'authentication_id' => Authentication::factory()->create()->getKey(),
            'plan_id' => $this->plan->getKey(),
            'active' => true,
            'payment_id' => null,
        ]);

        $this->actingAs($this->admin, 'sanctum');

        $response = $this->getJson('/api/v1/dashboard/admin/refunds')->assertStatus(200);

        expect($response->json('data.total'))->toBe(1)
            ->and($response->json('data.data.0.id'))->toBe($paid->getKey());
    });

    it('shows the amount actually paid, not the plan price', function () {
        // Cupom e crédito de troca fazem o cobrado divergir do preço de tabela; a
        // tela de estorno tem de mostrar o que saiu do cartão.
        paidSubscription(['payment_amount' => 42.50]);

        $this->actingAs($this->admin, 'sanctum');

        $this->getJson('/api/v1/dashboard/admin/refunds')
            ->assertStatus(200)
            ->assertJsonPath('data.data.0.payment_amount', 'R$ 42,50');
    });
});

describe('Processing a refund', function () {
    it('refunds the payment and deactivates the subscription', function () {
        $userPlan = paidSubscription();

        // Primeira resposta: o GET do pagamento. Segunda: o POST do estorno.
        $this->gateway
            ->pushResponse(['id' => 4400111222, 'status' => 'approved', 'transaction_amount' => 100.00])
            ->pushResponse(['id' => 987, 'status' => 'approved', 'amount' => 100.00]);

        $this->actingAs($this->admin, 'sanctum');

        // refund_id sai como string, igual a payment_id e à coluna que o guarda.
        $this->postJson("/api/v1/dashboard/admin/refunds/{$userPlan->getKey()}")
            ->assertStatus(200)
            ->assertJsonPath('data.refund_id', '987');

        $fresh = $userPlan->fresh();

        // O painel passou a gravar o mesmo rastro do estorno automático: antes só
        // marcava active = false, e não havia como distinguir uma assinatura
        // encerrada de uma devolvida.
        expect($fresh->active)->toBeFalse()
            ->and($fresh->refunded_at)->not->toBeNull()
            ->and($fresh->refund_id)->toBe('987');
    });

    it('refunds the amount the gateway reports, not the one stored locally', function () {
        // payment_amount é o registro do pacote; o saldo real do pagamento é o do
        // gateway. Estornar mais do que existe é recusado lá.
        paidSubscription(['payment_amount' => 999.00]);

        $this->gateway
            ->pushResponse(['id' => 4400111222, 'status' => 'approved', 'transaction_amount' => 100.00])
            ->pushResponse(['id' => 987, 'status' => 'approved']);

        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/v1/dashboard/admin/refunds/'.UserPlan::first()->getKey())->assertStatus(200);

        expect($this->gateway->payload(1)['amount'])->toEqual(100);
    });

    it('404s a subscription that was never paid', function () {
        $unpaid = UserPlan::factory()->create([
            'authentication_id' => Authentication::factory()->create()->getKey(),
            'plan_id' => $this->plan->getKey(),
            'payment_id' => null,
        ]);

        $this->actingAs($this->admin, 'sanctum');

        $this->postJson("/api/v1/dashboard/admin/refunds/{$unpaid->getKey()}")->assertStatus(404);

        expect($this->gateway->requests)->toBeEmpty();
    });

    it('never leaks the gateway error into the response', function () {
        // O defeito corrigido: $e->getMessage() ia concatenado para a tela, e o
        // catch é genérico — podia ser detalhe interno do Mercado Pago ou uma
        // QueryException com o SQL e os valores bindados, a caminho de um
        // screenshot em ticket de suporte.
        $userPlan = paidSubscription();

        $this->gateway->pushFailure([
            'message' => 'internal detail: card_token 4111111111111111 belongs to customer cus_42',
            'error' => 'bad_request',
        ]);

        $this->actingAs($this->admin, 'sanctum');

        $response = $this->postJson("/api/v1/dashboard/admin/refunds/{$userPlan->getKey()}")
            ->assertStatus(410);

        $body = $response->getContent();

        expect($body)->not->toContain('4111111111111111')
            ->and($body)->not->toContain('cus_42')
            ->and($body)->not->toContain('internal detail');
    });

    it('answers with a correlation code that is also in the log', function () {
        // O operador precisa de algo para levar ao suporte; o detalhe fica no log.
        //
        // O duplo substitui o singleton que os helpers loggly*() resolvem. Os
        // metodos fluentes devolvem a propria instancia, entao o mock precisa
        // fazer o mesmo para a cadeia chegar ate log().
        $captured = [];

        $loggly = Mockery::mock(Loggly::class);
        $loggly->shouldReceive('level')->andReturnSelf();
        $loggly->shouldReceive('withContext')
            ->andReturnUsing(function (array $context) use ($loggly, &$captured) {
                $captured = $context;

                return $loggly;
            });
        $loggly->shouldReceive('log')->once()->with('Refund failed');

        app()->instance(Loggly::class, $loggly);

        $userPlan = paidSubscription();
        $this->gateway->pushFailure(['message' => 'gateway exploded']);

        $this->actingAs($this->admin, 'sanctum');

        $message = $this->postJson("/api/v1/dashboard/admin/refunds/{$userPlan->getKey()}")
            ->assertStatus(410)
            ->json('message');

        expect($message)->toMatch('/[A-Z0-9]{8}/')
            ->and($captured['user_plan_id'])->toBe($userPlan->getKey())
            ->and($captured['payment_id'])->toBe($userPlan->payment_id)
            ->and($captured['error_id'])->not->toBeEmpty();
    });

    it('keeps the subscription active when the refund fails', function () {
        // Desativar o plano de quem não recebeu o dinheiro de volta é o pior
        // desfecho: o cliente perde o acesso e continua pago.
        $userPlan = paidSubscription();
        $this->gateway->pushFailure(['message' => 'refund refused']);

        $this->actingAs($this->admin, 'sanctum');

        $this->postJson("/api/v1/dashboard/admin/refunds/{$userPlan->getKey()}")->assertStatus(410);

        expect($userPlan->fresh()->active)->toBeTrue();
    });
});

describe('User search', function () {
    it('matches on name and e-mail, case-insensitively', function () {
        Authentication::factory()->create(['name' => 'Marina Alvez', 'email' => 'marina@example.com']);
        Authentication::factory()->create(['name' => 'Outro Alguem', 'email' => 'outro@example.com']);

        $this->actingAs($this->admin, 'sanctum');

        expect($this->getJson('/api/v1/dashboard/admin/users?search=MARINA')->json('data.total'))->toBe(1)
            ->and($this->getJson('/api/v1/dashboard/admin/users?search=outro@example')->json('data.total'))->toBe(1);
    });

    it('treats % and _ as literal characters', function () {
        // Sem o escape, um % digitado pelo operador vira curinga e a busca devolve
        // a base inteira — o oposto de filtrar.
        Authentication::factory()->create(['name' => 'Zed', 'email' => 'zed@example.com']);

        $this->actingAs($this->admin, 'sanctum');

        $this->getJson('/api/v1/dashboard/admin/users?search=%')
            ->assertStatus(200)
            ->assertJsonPath('data.total', 0);
    });
});
