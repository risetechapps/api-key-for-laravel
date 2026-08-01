<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RiseTechApps\ApiKey\Events\ApiKeyCreated;
use RiseTechApps\ApiKey\Events\ApiKeyStatusChanged;
use RiseTechApps\ApiKey\Events\PlanChanged;
use RiseTechApps\ApiKey\Events\PlanExpired;
use RiseTechApps\ApiKey\Events\PlanGracePeriodStarted;
use RiseTechApps\ApiKey\Events\RequestLimitReached;
use RiseTechApps\ApiKey\Events\UserStatusChanged;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

uses(RefreshDatabase::class);

// Apenas os eventos de domínio são falsificados. Event::fake() sem argumentos
// intercepta TAMBÉM os eventos de model do Eloquent (creating/saving), o que
// impede o HasUuid de gerar o id e o ApiKey de hashear a key — quebrando toda
// criação. Listando os eventos explicitamente, o ciclo de vida do model segue
// normal e só as asserções de evento são capturadas.
$domainEvents = [
    UserStatusChanged::class,
    ApiKeyCreated::class,
    ApiKeyStatusChanged::class,
    PlanChanged::class,
    PlanExpired::class,
    RequestLimitReached::class,
    PlanGracePeriodStarted::class,
];

beforeEach(function () use ($domainEvents) {
    $this->user = Authentication::factory()->create();
    $this->plan = Plan::factory()->create();

    Event::fake($domainEvents);
});

describe('User Status Changed Event', function () {
    it('dispatches when user status changes', function () {
        // status não está em $fillable (protegido de mass-assignment), então é
        // atribuído diretamente antes de salvar.
        $this->user->status = 'disabled';
        $this->user->save();

        Event::assertDispatched(UserStatusChanged::class, function ($event) {
            return $event->user->id === $this->user->id
                && $event->oldStatus === 'enabled'
                && $event->newStatus === 'disabled';
        });
    });
});

describe('Api Key Events', function () {
    it('dispatches ApiKeyCreated when key is created', function () {
        ApiKey::factory()->create([
            'authentication_id' => $this->user->id,
        ]);

        Event::assertDispatched(ApiKeyCreated::class, function ($event) {
            return $event->user->id === $this->user->id;
        });
    });

    it('dispatches ApiKeyStatusChanged when key status changes', function () {
        $apiKey = ApiKey::factory()->create([
            'authentication_id' => $this->user->id,
            'active' => true,
        ]);

        Event::fake([ApiKeyStatusChanged::class]); // Reset to capture only the update

        $apiKey->update(['active' => false]);

        Event::assertDispatched(ApiKeyStatusChanged::class, function ($event) use ($apiKey) {
            return $event->apiKey->id === $apiKey->id
                && $event->oldStatus === true
                && $event->newStatus === false;
        });
    });
});

describe('Plan Events', function () {
    it('dispatches PlanChanged when user subscribes', function () {
        $this->user->subscribeToPlan($this->plan);

        Event::assertDispatched(PlanChanged::class, function ($event) {
            return $event->user->id === $this->user->id
                && $event->plan->id === $this->plan->id;
        });
    });
});

describe('Request Limit Reached Event', function () {
    it('dispatches when request limit is reached', function () {
        // This would be tested through the middleware
        // Here we're just testing the event structure
        $userPlan = UserPlan::factory()->create([
            'authentication_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'requests_used' => 1000,
        ]);

        event(new RequestLimitReached(
            $this->user,
            $userPlan,
            $this->plan,
            1000,
            1000
        ));

        Event::assertDispatched(RequestLimitReached::class, function ($event) {
            return $event->requestsUsed === 1000
                && $event->requestsLimit === 1000;
        });
    });
});

describe('Plan Grace Period Started Event', function () {
    it('can be dispatched manually', function () {
        $userPlan = UserPlan::factory()->create([
            'authentication_id' => $this->user->id,
            'plan_id' => $this->plan->id,
        ]);

        event(new PlanGracePeriodStarted(
            $this->user,
            $userPlan,
            $this->plan,
            3,
            now()->addDays(3)
        ));

        Event::assertDispatched(PlanGracePeriodStarted::class, function ($event) {
            return $event->gracePeriodDays === 3;
        });
    });
});
