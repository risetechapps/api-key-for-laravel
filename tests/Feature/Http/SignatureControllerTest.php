<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = Authentication::factory()->create();
    $this->actingAs($this->user, 'sanctum');
});

describe('Free subscription endpoint', function () {
    it('refuses to hand out a paid plan', function () {
        // O buraco que isto cobre: o endpoint concede a assinatura direto, sem
        // passar pelo gateway. Sem a checagem de preço, qualquer usuário logado
        // POSTava o id do plano mais caro e o recebia de graça.
        $plan = Plan::factory()->create(['price' => 199.90, 'is_active' => true]);

        $response = $this->postJson('/api/v1/dashboard/signature', [
            'plan' => $plan->id,
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseCount('user_plans', 0);
        $this->assertDatabaseMissing('api_keys', ['authentication_id' => $this->user->id, 'active' => true]);
    });

    it('activates a free plan', function () {
        $plan = Plan::factory()->create(['price' => 0, 'is_active' => true]);

        $response = $this->postJson('/api/v1/dashboard/signature', [
            'plan' => $plan->id,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('user_plans', [
            'authentication_id' => $this->user->id,
            'plan_id' => $plan->id,
            'active' => true,
        ]);
    });

    it('refuses a plan that is no longer on sale', function () {
        // is_active só era filtrado na listagem do catálogo, mas o id viaja no
        // corpo da requisição — quem soubesse o id assinava um plano fora de venda.
        $plan = Plan::factory()->create(['price' => 0, 'is_active' => false]);

        $response = $this->postJson('/api/v1/dashboard/signature', [
            'plan' => $plan->id,
        ]);

        $response->assertStatus(404);

        $this->assertDatabaseCount('user_plans', 0);
    });

    it('no longer demands a payment method', function () {
        // `method`/`method_data` eram obrigatórios e nunca lidos: o cliente tinha
        // que inventar uma forma de pagamento para assinar o que não cobra nada.
        $plan = Plan::factory()->create(['price' => 0, 'is_active' => true]);

        $this->postJson('/api/v1/dashboard/signature', ['plan' => $plan->id])
            ->assertStatus(200);
    });

    it('requires authentication', function () {
        $plan = Plan::factory()->create(['price' => 0, 'is_active' => true]);

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/dashboard/signature', ['plan' => $plan->id])
            ->assertStatus(401);
    });
});
