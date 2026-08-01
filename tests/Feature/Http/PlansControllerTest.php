<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Authentication::factory()->create(['role' => 'admin']);
    $this->user = Authentication::factory()->create(['role' => 'user']);
});

function planPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Plano Profissional',
        'description' => 'Para times pequenos',
        'request_limit' => 50000,
        'price' => 149.90,
        'billing_cycle' => 'monthly',
        'is_active' => true,
        'features' => ['relatorios'],
    ], $overrides);
}

describe('Plan catalogue', function () {
    it('is public', function () {
        // A vitrine tem de abrir para quem ainda não tem conta; é a página que
        // vende o produto.
        Plan::factory()->create(['is_active' => true]);

        $this->getJson('/api/v1/dashboard/plans')->assertStatus(200);
    });

    it('lists only plans that are on sale', function () {
        Plan::factory()->create(['name' => 'A venda', 'is_active' => true]);
        Plan::factory()->inactive()->create(['name' => 'Fora de venda']);

        $names = collect($this->getJson('/api/v1/dashboard/plans')->json('data'))->pluck('name');

        expect($names)->toHaveCount(1)
            ->and($names->first())->not->toContain('Fora de venda');
    });

    it('serves the catalogue from cache', function () {
        Plan::factory()->create(['is_active' => true]);

        $this->getJson('/api/v1/dashboard/plans')->assertStatus(200);

        // Um plano criado depois do primeiro GET não aparece até o cache cair —
        // é o comportamento pretendido, e é o que store/update/delete invalidam.
        Plan::factory()->create(['is_active' => true]);

        expect($this->getJson('/api/v1/dashboard/plans')->json('data'))->toHaveCount(1);
    });

    it('reports a plan that does not exist', function () {
        $this->actingAs($this->user, 'sanctum');

        $this->getJson('/api/v1/dashboard/plans/'.\Illuminate\Support\Str::uuid())->assertStatus(410);
    });

    it('requires authentication to read a single plan', function () {
        $plan = Plan::factory()->create();

        $this->getJson("/api/v1/dashboard/plans/{$plan->getKey()}")->assertStatus(401);
    });
});

describe('Plan administration', function () {
    it('refuses writes from a non-admin', function () {
        $plan = Plan::factory()->create();
        $this->actingAs($this->user, 'sanctum');

        $this->postJson('/api/v1/dashboard/plans', planPayload())->assertStatus(403);
        $this->putJson("/api/v1/dashboard/plans/{$plan->getKey()}", planPayload())->assertStatus(403);
        $this->deleteJson("/api/v1/dashboard/plans/{$plan->getKey()}")->assertStatus(403);

        expect(Plan::count())->toBe(1);
    });

    it('creates a plan', function () {
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/v1/dashboard/plans', planPayload())->assertStatus(200);

        expect(Plan::where('name', 'PLANO PROFISSIONAL')->exists())->toBeTrue();
    });

    it('refuses a duplicate plan name', function () {
        Plan::factory()->create(['name' => 'PLANO PROFISSIONAL']);
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/v1/dashboard/plans', planPayload(['name' => 'PLANO PROFISSIONAL']))
            ->assertStatus(422);
    });

    it('refuses a name that only differs in case, though without a field error', function () {
        // Documenta uma folga conhecida: o `unique:plans,name` roda sobre o nome
        // como veio ('Plano Profissional'), mas o pacote to-upper normaliza para
        // maiúsculas na gravação. A validação passa, o índice único do banco
        // recusa, e o operador recebe a mensagem genérica de erro em vez de um
        // erro no campo. Não duplica o plano — só informa mal.
        Plan::factory()->create(['name' => 'PLANO PROFISSIONAL']);
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/v1/dashboard/plans', planPayload(['name' => 'Plano Profissional']))
            ->assertStatus(410);

        expect(Plan::count())->toBe(1);
    });

    it('refuses a free plan through this endpoint', function () {
        // As regras exigem price >= 0.01; plano gratuito não passa por aqui.
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/v1/dashboard/plans', planPayload(['price' => 0]))->assertStatus(422);
    });

    it('drops the catalogue cache when a plan is created', function () {
        Plan::factory()->create(['is_active' => true]);
        $this->getJson('/api/v1/dashboard/plans')->assertStatus(200);

        $this->actingAs($this->admin, 'sanctum');
        $this->postJson('/api/v1/dashboard/plans', planPayload())->assertStatus(200);

        // Sem o Cache::forget o plano novo ficaria invisível na vitrine por dez
        // minutos depois de ter sido publicado.
        expect(Cache::has('api_key:plans:active'))->toBeFalse()
            ->and($this->getJson('/api/v1/dashboard/plans')->json('data'))->toHaveCount(2);
    });

    it('updates a plan and drops the cache', function () {
        $plan = Plan::factory()->create(['is_active' => true, 'price' => 10.00]);
        $this->getJson('/api/v1/dashboard/plans')->assertStatus(200);

        $this->actingAs($this->admin, 'sanctum');
        $this->putJson("/api/v1/dashboard/plans/{$plan->getKey()}", planPayload(['price' => 199.90]))
            ->assertStatus(200);

        expect((float) $plan->fresh()->price)->toBe(199.90)
            ->and(Cache::has('api_key:plans:active'))->toBeFalse();
    });

    it('deletes a plan and drops the cache', function () {
        $plan = Plan::factory()->create(['is_active' => true]);
        $this->getJson('/api/v1/dashboard/plans')->assertStatus(200);

        $this->actingAs($this->admin, 'sanctum');
        $this->deleteJson("/api/v1/dashboard/plans/{$plan->getKey()}")->assertStatus(200);

        expect(Plan::find($plan->getKey()))->toBeNull()
            ->and(Cache::has('api_key:plans:active'))->toBeFalse();
    });

    it('reports deleting a plan that is not there', function () {
        $this->actingAs($this->admin, 'sanctum');

        $this->deleteJson('/api/v1/dashboard/plans/'.\Illuminate\Support\Str::uuid())->assertStatus(410);
    });
});
