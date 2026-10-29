<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\AuditLog\AuditLog;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Notifications\ApiKeyExpiringNotification;
use RiseTechApps\ApiKey\Notifications\OverageStartedNotification;
use RiseTechApps\ApiKey\Notifications\PaymentRejectedNotification;
use RiseTechApps\ApiKey\Notifications\PlanExpiredNotification;
use RiseTechApps\ApiKey\Notifications\RequestLimitReachedNotification;
use RiseTechApps\ApiKey\Notifications\UsageThresholdNotification;
use RiseTechApps\ApiKey\Support\Team;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = Authentication::factory()->create();
    $this->plan = Plan::factory()->create(['request_limit' => 100, 'overage_price' => 0.01]);
    $this->userPlan = UserPlan::factory()->create([
        'authentication_id' => $this->user->getKey(),
        'plan_id' => $this->plan->getKey(),
        'active' => true,
        'end_date' => now()->addDays(10),
    ]);
});

/** Um de cada aviso informativo, na ordem dos construtores do pacote. */
function informationalNotices(): array
{
    $apiKey = ApiKey::factory()->create(['authentication_id' => test()->user->getKey(), 'expires_at' => now()->addDays(3)]);

    return [
        new UsageThresholdNotification(test()->plan, test()->userPlan, 80, 100, 80),
        new ApiKeyExpiringNotification($apiKey, 3),
        new OverageStartedNotification(test()->plan, test()->userPlan, null),
    ];
}

describe('Preferência no perfil', function () {
    it('vem ligada por padrão', function () {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/dashboard/profile')
            ->assertOk()
            ->assertJsonPath('data.notifications.usage_notices', true);
    });

    it('o dono desliga, o perfil reflete e a troca vai para a auditoria', function () {
        Sanctum::actingAs($this->user);

        $this->putJson('/api/v1/dashboard/profile/notifications', ['usage_notices' => false])
            ->assertOk()
            ->assertJsonPath('data.usage_notices', false);

        expect($this->user->fresh()->usage_notices_enabled)->toBeFalse();
        $this->getJson('/api/v1/dashboard/profile')->assertJsonPath('data.notifications.usage_notices', false);

        $log = AuditLog::where('action', 'account.notification_preferences_changed')->sole();
        expect($log->context)->toMatchArray(['from' => ['usage_notices' => true], 'to' => ['usage_notices' => false]]);
    });

    it('salvar o mesmo valor não gera auditoria', function () {
        Sanctum::actingAs($this->user);

        $this->putJson('/api/v1/dashboard/profile/notifications', ['usage_notices' => true])->assertOk();

        expect(AuditLog::where('action', 'account.notification_preferences_changed')->exists())->toBeFalse();
    });

    it('exige um booleano', function () {
        Sanctum::actingAs($this->user);

        $this->putJson('/api/v1/dashboard/profile/notifications', [])->assertUnprocessable();
        $this->putJson('/api/v1/dashboard/profile/notifications', ['usage_notices' => 'talvez'])->assertUnprocessable();
    });

    it('membro da equipe não muda: os avisos vão para o dono', function () {
        $admin = Authentication::factory()->create();
        $admin->forceFill(['account_id' => $this->user->getKey(), 'team_role' => Team::ADMIN])->save();
        Sanctum::actingAs($admin);

        $this->putJson('/api/v1/dashboard/profile/notifications', ['usage_notices' => false])->assertForbidden();

        expect($this->user->fresh()->usage_notices_enabled)->toBeTrue();
    });
});

describe('Envio', function () {
    it('manda os avisos informativos com a preferência ligada', function () {
        Notification::fake();

        foreach (informationalNotices() as $notice) {
            $this->user->notify($notice);
            Notification::assertSentTo($this->user, $notice::class);
        }
    });

    it('não manda os avisos informativos com a preferência desligada', function () {
        Notification::fake();
        $this->user->forceFill(['usage_notices_enabled' => false])->save();

        foreach (informationalNotices() as $notice) {
            $this->user->notify($notice);
            Notification::assertNotSentTo($this->user, $notice::class);
        }
    });

    it('cobrança, limite e expiração saem mesmo com a preferência desligada', function () {
        Notification::fake();
        $this->user->forceFill(['usage_notices_enabled' => false])->save();

        $this->user->notify(new PaymentRejectedNotification($this->plan, 49.9));
        $this->user->notify(new RequestLimitReachedNotification($this->plan, $this->userPlan, 100, 100));
        $this->user->notify(new PlanExpiredNotification($this->plan, $this->userPlan));

        Notification::assertSentTo($this->user, PaymentRejectedNotification::class);
        Notification::assertSentTo($this->user, RequestLimitReachedNotification::class);
        Notification::assertSentTo($this->user, PlanExpiredNotification::class);
    });

    it('o envio real (sem fake) também respeita a preferência', function () {
        config(['mail.default' => 'array']);
        $notice = new UsageThresholdNotification($this->plan, $this->userPlan, 80, 100, 80);

        $this->user->notify($notice);
        expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(1);

        $this->user->forceFill(['usage_notices_enabled' => false])->save();
        $this->user->notify($notice);
        expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(1);
    });
});
