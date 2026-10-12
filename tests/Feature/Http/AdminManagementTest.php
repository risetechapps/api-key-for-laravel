<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RiseTechApps\ApiKey\Jobs\ProcessPlanRenewalJob;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\AuditLog\AuditLog;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\PendingPayment\PendingPayment;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Notifications\AccountSecurityNotification;
use RiseTechApps\ApiKey\Services\SystemHealthService;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    $this->admin = Authentication::factory()->create(['role' => 'admin']);
    $this->finance = Authentication::factory()->create(['role' => 'finance']);
    $this->support = Authentication::factory()->create(['role' => 'support']);
    $this->customer = Authentication::factory()->create(['name' => 'Cliente Teste', 'email' => 'cliente@example.com']);
    $this->plan = Plan::factory()->create(['price' => 50.00, 'request_limit' => 100]);
});

function amSubscription(array $attributes = []): UserPlan
{
    return UserPlan::factory()->create([
        'authentication_id' => test()->customer->getKey(),
        'plan_id' => test()->plan->getKey(),
        'active' => true,
        'start_date' => now()->subDays(10),
        'end_date' => now()->addDays(20),
        ...$attributes,
    ]);
}

describe('Team roles', function () {
    it('lets every staff role read, and nobody else', function () {
        foreach (['admin', 'finance', 'support'] as $role) {
            $this->actingAs($this->{$role}, 'sanctum')->getJson('/api/v1/dashboard/admin/users')->assertOk();
        }

        $this->actingAs($this->customer, 'sanctum')->getJson('/api/v1/dashboard/admin/users')->assertForbidden();
    });

    it('keeps money with admin and finance', function () {
        $userPlan = amSubscription();

        $this->actingAs($this->support, 'sanctum')
            ->postJson("/api/v1/dashboard/admin/users/{$this->customer->getKey()}/subscriptions/{$userPlan->getKey()}/extend", ['days' => 5])
            ->assertForbidden()
            ->assertJsonPath('code', 'admin_ability_required');

        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/admin/users/{$this->customer->getKey()}/subscriptions/{$userPlan->getKey()}/extend", ['days' => 5])
            ->assertOk();
    });

    it('keeps account actions with admin and support', function () {
        $this->actingAs($this->finance, 'sanctum')
            ->postJson("/api/v1/dashboard/admin/users/{$this->customer->getKey()}/sessions/revoke")
            ->assertForbidden();

        $this->actingAs($this->support, 'sanctum')
            ->postJson("/api/v1/dashboard/admin/users/{$this->customer->getKey()}/sessions/revoke")
            ->assertOk();
    });

    it('keeps configuration, roles and 2FA reset with admin only', function () {
        foreach (['finance', 'support'] as $role) {
            $this->actingAs($this->{$role}, 'sanctum')->getJson('/api/v1/dashboard/admin/payment-settings')->assertForbidden();
            $this->actingAs($this->{$role}, 'sanctum')
                ->putJson("/api/v1/dashboard/admin/users/{$this->customer->getKey()}/role", ['role' => 'admin'])
                ->assertForbidden();
        }
    });

    it('requires 2FA from the whole team when configured', function () {
        config(['api-key.two_factor.required_for_admins' => true]);

        $this->actingAs($this->support, 'sanctum')
            ->getJson('/api/v1/dashboard/admin/users')
            ->assertForbidden()
            ->assertJsonPath('code', 'two_factor_required');
    });

    it('promotes and demotes, signing out who lost power', function () {
        $this->support->createToken('panel');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/dashboard/admin/users/{$this->support->getKey()}/role", ['role' => 'client'])
            ->assertOk();

        expect($this->support->fresh()->role)->toBe('client')
            ->and($this->support->tokens()->count())->toBe(0)
            ->and(AuditLog::where('action', 'admin.role_changed')->exists())->toBeTrue();
    });

    it('does not let anyone change their own role', function () {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/dashboard/admin/users/{$this->admin->getKey()}/role", ['role' => 'client'])
            ->assertStatus(422);

        expect($this->admin->fresh()->role)->toBe('admin');
    });
});

describe('User profile in the admin panel', function () {
    it('shows subscriptions, cards, keys, sessions and audit, masking the CPF', function () {
        $this->customer->forceFill(['cpf' => '529.982.247-25'])->save();
        amSubscription(['payment_id' => '123', 'payment_amount' => 50]);
        $this->customer->createToken('panel');

        $this->actingAs($this->support, 'sanctum')
            ->getJson("/api/v1/dashboard/admin/users/{$this->customer->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.user.email', 'cliente@example.com')
            ->assertJsonPath('data.user.cpf', '***.***.***-25')
            ->assertJsonPath('data.current.plan', $this->plan->name)
            ->assertJsonPath('data.subscriptions.0.payment_id', '123')
            ->assertJsonCount(1, 'data.sessions');
    });
});

describe('Subscription actions', function () {
    beforeEach(fn () => $this->actingAs($this->admin, 'sanctum'));

    it('extends the due date and clears a stuck renewal', function () {
        $userPlan = amSubscription(['renewal_requires_payment' => 'cvv_required', 'renewal_failed_at' => now()]);
        $end = $userPlan->end_date->copy();

        $this->postJson("/api/v1/dashboard/admin/users/{$this->customer->getKey()}/subscriptions/{$userPlan->getKey()}/extend", ['days' => 10])
            ->assertOk();

        $userPlan->refresh();
        expect($userPlan->end_date->toDateString())->toBe($end->addDays(10)->toDateString())
            ->and($userPlan->renewal_requires_payment)->toBeNull()
            ->and($userPlan->renewal_failed_at)->toBeNull();
    });

    it('cancels the renewal keeping access', function () {
        $userPlan = amSubscription();

        $this->postJson("/api/v1/dashboard/admin/users/{$this->customer->getKey()}/subscriptions/{$userPlan->getKey()}/cancel")->assertOk();

        expect($userPlan->fresh()->cancelled_at)->not->toBeNull()
            ->and($userPlan->fresh()->active)->toBeTrue();
    });

    it('adjusts the period usage', function () {
        $userPlan = amSubscription(['requests_used' => 90]);

        $this->putJson("/api/v1/dashboard/admin/users/{$this->customer->getKey()}/subscriptions/{$userPlan->getKey()}/usage", ['requests_used' => 10])->assertOk();

        expect($userPlan->fresh()->requests_used)->toBe(10);
    });

    it('queues a renewal retry', function () {
        Queue::fake();
        $userPlan = amSubscription(['renewal_failed_at' => now()]);

        $this->postJson("/api/v1/dashboard/admin/users/{$this->customer->getKey()}/subscriptions/{$userPlan->getKey()}/retry-renewal")->assertOk();

        Queue::assertPushed(ProcessPlanRenewalJob::class, fn ($job) => $job->userPlanId === $userPlan->getKey());
        expect($userPlan->fresh()->renewal_failed_at)->toBeNull();
    });

    it('waives an unpaid overage, keeping the amount in the audit', function () {
        $userPlan = amSubscription(['end_date' => now()->subDay(), 'overage_billed_at' => now(), 'overage_amount' => 7.5, 'overage_requests' => 75]);

        $this->postJson("/api/v1/dashboard/admin/users/{$this->customer->getKey()}/subscriptions/{$userPlan->getKey()}/waive-overage")->assertOk();

        expect((float) $userPlan->fresh()->overage_amount)->toBe(0.0)
            ->and(AuditLog::where('action', 'admin.overage_waived')->first()->context['amount'])->toEqual(7.5);
    });

    it('does not touch a subscription of another user', function () {
        $other = UserPlan::factory()->create(['plan_id' => $this->plan->getKey()]);

        $this->postJson("/api/v1/dashboard/admin/users/{$this->customer->getKey()}/subscriptions/{$other->getKey()}/extend", ['days' => 10])
            ->assertNotFound();
    });
});

describe('Account actions', function () {
    beforeEach(fn () => $this->actingAs($this->admin, 'sanctum'));

    it('turns off 2FA, signs out and warns the owner', function () {
        $this->customer->forceFill(['two_factor_secret' => 'secret', 'two_factor_confirmed_at' => now()])->save();
        $this->customer->createToken('panel');

        $this->postJson("/api/v1/dashboard/admin/users/{$this->customer->getKey()}/two-factor/reset")->assertOk();

        $fresh = $this->customer->fresh();
        expect($fresh->two_factor_confirmed_at)->toBeNull()
            ->and($fresh->two_factor_secret)->toBeNull()
            ->and($fresh->tokens()->count())->toBe(0);

        Notification::assertSentTo($this->customer, AccountSecurityNotification::class, fn ($n) => $n->event === AccountSecurityNotification::TWO_FACTOR_DISABLED);
    });

    it('resends the verification email only when not verified', function () {
        $this->customer->forceFill(['email_verified_at' => null])->save();
        $this->postJson("/api/v1/dashboard/admin/users/{$this->customer->getKey()}/verification")->assertOk();

        $this->customer->forceFill(['email_verified_at' => now()])->save();
        $this->postJson("/api/v1/dashboard/admin/users/{$this->customer->getKey()}/verification")->assertStatus(422);
    });

    it('deactivates the API keys', function () {
        $key = ApiKey::factory()->create(['authentication_id' => $this->customer->getKey(), 'active' => true]);

        $this->postJson("/api/v1/dashboard/admin/users/{$this->customer->getKey()}/api-keys/revoke")->assertOk();

        expect($key->fresh()->active)->toBeFalse();
    });
});

describe('Payments, delinquency and exports', function () {
    beforeEach(fn () => $this->actingAs($this->finance, 'sanctum'));

    it('lists every payment with filters and totals', function () {
        amSubscription(['payment_id' => '111', 'payment_amount' => 50, 'payment_method' => 'card']);
        amSubscription(['payment_id' => 'or_222', 'payment_amount' => 30, 'payment_method' => 'pix', 'refunded_at' => now(), 'active' => false]);
        PendingPayment::create(['authentication_id' => $this->customer->getKey(), 'plan_id' => $this->plan->getKey(), 'payment_id' => '333', 'amount' => 50, 'status' => 'in_process']);

        $this->getJson('/api/v1/dashboard/admin/payments')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.summary.approved.amount', 50)
            ->assertJsonPath('data.summary.refunded.count', 1)
            ->assertJsonPath('data.summary.pending.count', 1);

        $this->getJson('/api/v1/dashboard/admin/payments?gateway=pagarme')
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.gateway', 'pagarme');

        $this->getJson('/api/v1/dashboard/admin/payments?search=cliente@example')
            ->assertJsonPath('data.total', 3);
    });

    it('lists money to recover', function () {
        amSubscription(['renewal_attempts' => 1, 'next_renewal_attempt_at' => now()->addDay()]);
        amSubscription(['renewal_requires_payment' => 'no_card']);
        amSubscription(['end_date' => now()->subDay(), 'overage_billed_at' => now(), 'overage_amount' => 4.2]);

        $this->getJson('/api/v1/dashboard/admin/delinquency')
            ->assertOk()
            ->assertJsonCount(1, 'data.renewals_retrying')
            ->assertJsonCount(1, 'data.renewals_awaiting_payment')
            ->assertJsonPath('data.totals.unpaid_overage', 4.2);
    });

    it('exports payments as CSV, neutralizing formulas', function () {
        $this->customer->forceFill(['name' => '=HYPERLINK("x")'])->save();
        amSubscription(['payment_id' => '111', 'payment_amount' => 50]);

        $response = $this->get('/api/v1/dashboard/admin/export/payments')->assertOk();
        $csv = $response->streamedContent();

        expect($csv)->toContain('id do pagamento')
            ->and($csv)->toContain('111')
            ->and($csv)->toContain("'=HYPERLINK")
            ->and(AuditLog::where('action', 'admin.export_downloaded')->exists())->toBeTrue();
    });
});

describe('System health', function () {
    it('reports the gateway, routines and pending payments', function () {
        config(['api-key.mercadopago.access_token' => 'TEST-x', 'api-key.mercadopago.webhook_secret' => 's']);
        SystemHealthService::recordCommand('billing:process-renewals', 0);
        SystemHealthService::recordWebhook('mercadopago');

        $checks = collect($this->actingAs($this->support, 'sanctum')
            ->getJson('/api/v1/dashboard/admin/health')
            ->assertOk()
            ->json('data.checks'))->keyBy('label');

        expect($checks['Gateway ativo: Mercado Pago']['status'])->toBe('ok')
            ->and($checks['Renovações']['status'])->toBe('ok')
            ->and($checks['Cobrança de excedente']['status'])->toBe('warning')
            ->and($checks['Último webhook do Mercado Pago']['status'])->toBe('ok');
    });

    it('flags an active gateway without credentials', function () {
        config(['api-key.payment.gateway' => 'pagarme', 'api-key.pagarme.secret_key' => null]);

        $checks = collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/dashboard/admin/health')->json('data.checks'))->keyBy('label');

        expect($checks['Gateway ativo: Pagar.me']['status'])->toBe('error');
    });

    it('shows the app checks added with extend(), and a broken one as a warning', function () {
        SystemHealthService::extend(fn () => [['group' => 'WhatsApp', 'label' => 'Fila whatsapp-chat', 'status' => 'error', 'detail' => '900 job(s) esperando.']]);
        SystemHealthService::extend(fn () => throw new RuntimeException('sem redis'));

        $checks = collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/dashboard/admin/health')->json('data.checks'))->keyBy('label');

        expect($checks['Fila whatsapp-chat'])->toMatchArray(['group' => 'WhatsApp', 'status' => 'error'])
            ->and($checks['Verificação extra']['detail'])->toContain('sem redis');
    });

    afterEach(function () {
        Cache::flush();
        SystemHealthService::forgetExtensions();
    });
});

describe('Appearance', function () {
    it('saves the brand and the Blade shell hands it to the panel', function () {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/dashboard/admin/payment-settings', ['values' => [
                'spa.brand.name' => 'Minha Empresa',
                'spa.brand.color' => '#16A34A',
            ]])
            ->assertOk();

        $html = view('api-key::app')->render();

        expect(config('api-key.spa.brand.color'))->toBe('#16a34a')
            ->and($html)->toContain('--brand: #16a34a')
            ->and($html)->toContain('<title>Minha Empresa</title>')
            ->and($html)->toContain('"name":"Minha Empresa"');
    });

    it('rejects a color that is not hexadecimal', function () {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/dashboard/admin/payment-settings', ['values' => ['spa.brand.color' => 'red;}body{display:none']])
            ->assertStatus(422);
    });

    it('never prints an unsafe color from the config into the style tag', function () {
        config(['api-key.spa.brand.color' => '#fff;}</style><script>alert(1)</script>']);

        $html = view('api-key::app')->render();

        expect($html)->toContain('--brand: #6366f1')
            ->and($html)->not->toContain('<script>alert(1)</script>');
    });

    it('is closed to finance and support', function () {
        $this->actingAs($this->finance, 'sanctum')
            ->putJson('/api/v1/dashboard/admin/payment-settings', ['values' => ['spa.brand.color' => '#000000']])
            ->assertForbidden();
    });
});
