<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use RiseTechApps\ApiKey\Events\PlanChanged;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\RequestLog\RequestLog;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Authentication::factory()->create(['role' => 'admin']);
    $this->user = Authentication::factory()->create();
    $this->plan = Plan::factory()->create(['request_limit' => 1000]);
});

/** Chave + plano ativos, o mínimo para o grupo `plan` deixar passar. */
function apiKeyWithPlan(Authentication $user, Plan $plan): ApiKey
{
    UserPlan::factory()->create([
        'authentication_id' => $user->getKey(),
        'plan_id' => $plan->getKey(),
        'active' => true,
        'end_date' => now()->addDays(10),
    ]);

    return ApiKey::factory()->create(['authentication_id' => $user->getKey(), 'active' => true]);
}

describe('Admin: user status', function () {
    it('suspends a user and revokes their dashboard tokens', function () {
        $this->user->createToken('session');
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/dashboard/admin/users/{$this->user->getKey()}/status", ['status' => 'blocked'])
            ->assertOk()
            ->assertJsonPath('data.status', 'blocked');

        expect($this->user->fresh()->status)->toBe('blocked')
            ->and($this->user->tokens()->count())->toBe(0);
    });

    it('reactivates a user', function () {
        $this->user->forceFill(['status' => 'disabled'])->save();
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/dashboard/admin/users/{$this->user->getKey()}/status", ['status' => 'enabled'])
            ->assertOk();

        expect($this->user->fresh()->isEnabled())->toBeTrue();
    });

    it('refuses to change the admin own status', function () {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/dashboard/admin/users/{$this->admin->getKey()}/status", ['status' => 'disabled'])
            ->assertStatus(422);

        expect($this->admin->fresh()->isEnabled())->toBeTrue();
    });

    it('validates the status value', function () {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/v1/dashboard/admin/users/{$this->user->getKey()}/status", ['status' => 'banana'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    });

    it('is admin only', function () {
        Sanctum::actingAs($this->user);

        $this->putJson("/api/v1/dashboard/admin/users/{$this->admin->getKey()}/status", ['status' => 'disabled'])
            ->assertForbidden();
    });
});

describe('Suspended accounts', function () {
    it('cannot use the dashboard', function () {
        $this->user->forceFill(['status' => 'disabled'])->save();
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJson(['message' => __('api-key::messages.account_disabled')]);
    });

    it('cannot use the API key, and gets it back on reactivation', function () {
        $apiKey = apiKeyWithPlan($this->user, $this->plan);
        $this->user->forceFill(['status' => 'blocked'])->save();

        $this->withHeaders(['X-API-KEY' => $apiKey->plainKey])
            ->getJson('/api/v1/test-endpoint')
            ->assertForbidden();

        $this->user->forceFill(['status' => 'enabled'])->save();

        $this->withHeaders(['X-API-KEY' => $apiKey->plainKey])
            ->getJson('/api/v1/test-endpoint')
            ->assertOk();
    });
});

describe('Admin: grant plan', function () {
    it('subscribes the user to the plan without payment', function () {
        Event::fake([PlanChanged::class]);
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/dashboard/admin/users/{$this->user->getKey()}/plan", ['plan_id' => $this->plan->getKey()])
            ->assertOk()
            ->assertJsonStructure(['data' => ['user_plan_id', 'end_date']]);

        $active = $this->user->fresh()->activePlan;

        expect($active->plan_id)->toBe($this->plan->getKey())
            ->and($active->payment_id)->toBeNull()
            ->and($this->user->apiKey()->first()?->active)->toBeTruthy();

        Event::assertDispatched(PlanChanged::class);
    });

    it('replaces the running subscription', function () {
        apiKeyWithPlan($this->user, $this->plan);
        $other = Plan::factory()->create();
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/dashboard/admin/users/{$this->user->getKey()}/plan", ['plan_id' => $other->getKey()])
            ->assertOk();

        expect(UserPlan::where('authentication_id', $this->user->getKey())->where('active', true)->count())->toBe(1)
            ->and($this->user->fresh()->activePlan->plan_id)->toBe($other->getKey());
    });

    it('404s for an unknown plan', function () {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/v1/dashboard/admin/users/{$this->user->getKey()}/plan", ['plan_id' => 'nope'])
            ->assertNotFound();
    });
});

describe('Data export', function () {
    it('downloads profile, subscriptions, cards and request logs, without secrets', function () {
        apiKeyWithPlan($this->user, $this->plan);
        UserCard::create([
            'authentication_id' => $this->user->getKey(),
            'holder_name' => 'ANA',
            'last_four' => '4242',
            'brand' => 'visa',
            'expiry_month' => 3,
            'expiry_year' => 2030,
            'mp_customer_id' => 'cust-1',
            'mp_card_id' => 'card-1',
        ]);
        RequestLog::create([
            'authentication_id' => $this->user->getKey(),
            'endpoint' => '/api/v1/test-endpoint',
            'method' => 'GET',
            'response_code' => 200,
            'requested_at' => now(),
        ]);
        Sanctum::actingAs($this->user);

        $response = $this->get('/api/v1/dashboard/profile/export')->assertOk();

        expect($response->headers->get('content-disposition'))->toContain('attachment');

        $data = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

        expect($data['profile']['email'])->toBe($this->user->email)
            ->and($data['subscriptions'])->toHaveCount(1)
            ->and($data['cards'][0]['last_four'])->toBe('4242')
            ->and($data['cards'][0])->not->toHaveKey('mp_card_id')
            ->and($data['request_logs'])->toHaveCount(1)
            ->and($data['api_key'])->not->toHaveKey('key')
            ->and($response->streamedContent())->not->toContain('password');
    });
});

describe('Account deletion', function () {
    it('requires the current password', function () {
        Sanctum::actingAs($this->user);

        $this->deleteJson('/api/v1/dashboard/profile', ['password' => 'wrong-password'])
            ->assertStatus(422);

        expect($this->user->fresh())->not->toBeNull();
    });

    it('anonymizes the account, drops access and keeps the payment record', function () {
        $apiKey = apiKeyWithPlan($this->user, $this->plan);
        UserPlan::where('authentication_id', $this->user->getKey())->update(['payment_id' => '999', 'payment_amount' => 50]);
        $this->user->createToken('session');
        $email = $this->user->email;
        $id = $this->user->getKey();
        Sanctum::actingAs($this->user);

        $this->deleteJson('/api/v1/dashboard/profile', ['password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.message', __('api-key::messages.account_deleted'));

        $deleted = Authentication::withTrashed()->find($id);

        expect($deleted->trashed())->toBeTrue()
            ->and($deleted->email)->not->toBe($email)
            ->and($deleted->cpf)->toBeNull()
            ->and($deleted->isEnabled())->toBeFalse()
            ->and($deleted->tokens()->count())->toBe(0)
            ->and(ApiKey::where('authentication_id', $id)->exists())->toBeFalse()
            ->and(UserPlan::where('authentication_id', $id)->where('active', true)->exists())->toBeFalse()
            ->and(UserPlan::where('authentication_id', $id)->where('payment_id', '999')->exists())->toBeTrue();

        $this->withHeaders(['X-API-KEY' => $apiKey->plainKey])
            ->getJson('/api/v1/test-endpoint')
            ->assertUnauthorized();
    });

    it('frees the e-mail for a new registration', function () {
        $email = $this->user->email;
        Sanctum::actingAs($this->user);

        $this->deleteJson('/api/v1/dashboard/profile', ['password' => 'password'])->assertOk();

        expect(Authentication::where('email', $email)->exists())->toBeFalse();
    });

    it('is refused for an admin', function () {
        Sanctum::actingAs($this->admin);

        $this->deleteJson('/api/v1/dashboard/profile', ['password' => 'password'])
            ->assertStatus(422)
            ->assertJson(['message' => __('api-key::messages.account_delete_admin')]);

        expect($this->admin->fresh()->trashed())->toBeFalse();
    });
});
