<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'api-key.refund.window_days' => 7,
        'api-key.refund.max_usage_percent' => 50,
    ]);

    $this->user = Authentication::factory()->create();
    $this->plan = Plan::factory()->create(['price' => 100.00, 'request_limit' => 1000]);
    $this->actingAs($this->user, 'sanctum');
});

function previewSubscription(array $attributes = []): UserPlan
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

describe('Previa do cancelamento', function () {
    it('requer autenticacao', function () {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/dashboard/signature/refund-preview')->assertStatus(401);
    });

    it('404 quando nao ha assinatura ativa', function () {
        $this->getJson('/api/v1/dashboard/signature/refund-preview')->assertStatus(404);
    });

    it('anuncia o estorno e o fim imediato do acesso', function () {
        // É esta resposta que permite a tela dizer a verdade na confirmação, em
        // vez de prometer que nada será interrompido.
        previewSubscription();

        $this->getJson('/api/v1/dashboard/signature/refund-preview')
            ->assertStatus(200)
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.amount', 100)
            ->assertJsonPath('data.access_until', null);
    });

    it('anuncia o acesso ate o vencimento quando nao ha estorno', function () {
        $userPlan = previewSubscription(['start_date' => now()->subDays(10)]);

        $this->getJson('/api/v1/dashboard/signature/refund-preview')
            ->assertStatus(200)
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.reason', 'window_expired')
            ->assertJsonPath('data.access_until', $userPlan->end_date->toIso8601String());
    });

    it('explica a recusa por consumo', function () {
        previewSubscription(['requests_used' => 900]);

        $this->getJson('/api/v1/dashboard/signature/refund-preview')
            ->assertStatus(200)
            ->assertJsonPath('data.reason', 'usage_exceeded');
    });

    it('nao altera nada', function () {
        // Consulta pura: a tela chama isto a cada abertura do diálogo.
        $userPlan = previewSubscription();

        $this->getJson('/api/v1/dashboard/signature/refund-preview')->assertStatus(200);

        $fresh = $userPlan->fresh();

        expect($fresh->cancelled_at)->toBeNull()
            ->and($fresh->refunded_at)->toBeNull()
            ->and($fresh->active)->toBeTrue();
    });

    it('acompanha a politica desligada', function () {
        config(['api-key.refund.window_days' => 0]);

        previewSubscription();

        $this->getJson('/api/v1/dashboard/signature/refund-preview')
            ->assertStatus(200)
            ->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.reason', 'refund_disabled');
    });
});
