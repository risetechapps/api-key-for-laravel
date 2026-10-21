<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RiseTechApps\ApiKey\Events\PlanUsageThresholdReached;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = Authentication::factory()->create();
    $plan = Plan::factory()->create(['request_limit' => 10]);
    $this->userPlan = UserPlan::factory()->create([
        'authentication_id' => $this->user->getKey(),
        'plan_id' => $plan->getKey(),
        'active' => true,
        'end_date' => now()->addDays(10),
        'requests_used' => 7,
    ]);
});

it('does not spend the 80% warning on a request that was refused and not billed', function () {
    Event::fake([PlanUsageThresholdReached::class]);

    // Chave sem permissão para a feature da rota: recusada depois da reserva.
    $restricted = ApiKey::factory()->create([
        'authentication_id' => $this->user->getKey(),
        'active' => true,
        'scopes' => ['other-feature'],
    ]);

    $this->withHeaders(['X-API-KEY' => $restricted->plainKey])
        ->getJson('/api/v1/test-endpoint-feature')
        ->assertForbidden();

    expect($this->userPlan->fresh()->requests_used)->toBe(7);
    Event::assertNotDispatched(PlanUsageThresholdReached::class);

    // A 8ª requisição cobrada de verdade é a que avisa.
    $key = ApiKey::factory()->create(['authentication_id' => $this->user->getKey(), 'active' => true]);

    $this->withHeaders(['X-API-KEY' => $key->plainKey])->getJson('/api/v1/test-endpoint')->assertOk();

    Event::assertDispatched(PlanUsageThresholdReached::class, fn ($e) => $e->requestsUsed === 8);
});

it('does not warn on a server error, whose quota is handed back', function () {
    Event::fake([PlanUsageThresholdReached::class]);
    $key = ApiKey::factory()->create(['authentication_id' => $this->user->getKey(), 'active' => true]);

    $this->withHeaders(['X-API-KEY' => $key->plainKey])->getJson('/api/v1/test-endpoint-server-error')->assertStatus(500);

    Event::assertNotDispatched(PlanUsageThresholdReached::class);
});
