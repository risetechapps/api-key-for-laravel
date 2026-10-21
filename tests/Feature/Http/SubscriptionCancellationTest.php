<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RiseTechApps\ApiKey\Jobs\ProcessPlanRenewalJob;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Notifications\PlanCancelledNotification;
use RiseTechApps\ApiKey\Services\MpCustomerService;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = Authentication::factory()->create();
    $this->plan = Plan::factory()->create(['price' => 49.90]);
    $this->actingAs($this->user, 'sanctum');
});

/**
 * Vence hoje, mas ainda não venceu.
 *
 * Os casos de renovação precisam das duas coisas ao mesmo tempo: dentro da
 * janela do `billing:process-renewals` (`[today 00:00, today 23:59:59]`) e com
 * `isExpired()` falso (`now()->gt($end_date)`). O valor anterior era
 * `today()->addHours(3)`, ou seja 03:00 de hoje, que vira passado a partir das
 * 03:00 — o teste de `resume` recebia 422 e a suíte passava ou falhava conforme
 * a hora em que rodasse.
 */
function dueToday(): Carbon
{
    return now()->endOfDay();
}

function activeSubscription(array $attributes = []): UserPlan
{
    return UserPlan::factory()->create(array_merge([
        'authentication_id' => test()->user->id,
        'plan_id' => test()->plan->id,
        'active' => true,
        'start_date' => now()->subDays(2),
        'end_date' => now()->addDays(28),
    ], $attributes));
}

describe('Cancelling a subscription', function () {
    it('marks the subscription as cancelled', function () {
        $userPlan = activeSubscription();

        $this->postJson('/api/v1/dashboard/signature/cancel')->assertStatus(200);

        expect($userPlan->fresh()->isCancelled())->toBeTrue();
    });

    it('keeps access until the end of the paid period', function () {
        // O ponto todo: cancelar não é revogar. Quem cancela no dia 2 de 30 não
        // pode perder os 28 dias que já pagou.
        $userPlan = activeSubscription();

        $this->postJson('/api/v1/dashboard/signature/cancel')->assertStatus(200);

        $fresh = $userPlan->fresh();

        expect($fresh->active)->toBeTrue()
            ->and($fresh->isExpired())->toBeFalse()
            ->and($this->user->hasActivePlan())->toBeTrue();
    });

    it('reports when the access ends', function () {
        $userPlan = activeSubscription();

        $this->postJson('/api/v1/dashboard/signature/cancel')
            ->assertStatus(200)
            ->assertJsonPath('data.access_until', $userPlan->end_date->toIso8601String());
    });

    it('is idempotent', function () {
        // Um retry do cliente pede o estado que ele já pediu; falhar num segundo
        // cancelamento só transforma instabilidade de rede em erro na tela.
        $userPlan = activeSubscription();

        $this->postJson('/api/v1/dashboard/signature/cancel')->assertStatus(200);
        $firstCancelledAt = $userPlan->fresh()->cancelled_at;

        $this->postJson('/api/v1/dashboard/signature/cancel')->assertStatus(200);

        expect($userPlan->fresh()->cancelled_at->toIso8601String())
            ->toBe($firstCancelledAt->toIso8601String());
    });

    it('404s when there is nothing to cancel', function () {
        $this->postJson('/api/v1/dashboard/signature/cancel')->assertStatus(404);
    });

    it('requires authentication', function () {
        activeSubscription();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/dashboard/signature/cancel')->assertStatus(401);
    });
});

describe('Cancellation notification', function () {
    it('confirms the cancellation by e-mail', function () {
        // Cancelar em silêncio é o que gera o ticket de suporte: o cliente não
        // sabe se funcionou nem se ainda tem acesso.
        Notification::fake();

        activeSubscription();

        $this->postJson('/api/v1/dashboard/signature/cancel')->assertStatus(200);

        Notification::assertSentTo($this->user, PlanCancelledNotification::class);
    });

    it('does not send a second time on a repeated cancel', function () {
        Notification::fake();

        activeSubscription();

        $this->postJson('/api/v1/dashboard/signature/cancel');
        $this->postJson('/api/v1/dashboard/signature/cancel');

        Notification::assertSentToTimes($this->user, PlanCancelledNotification::class, 1);
    });
});

describe('Renewal after cancellation', function () {
    it('is not dispatched by the billing command', function () {
        Queue::fake();

        activeSubscription([
            'end_date' => dueToday(),
            'cancelled_at' => now(),
        ]);

        $this->artisan('billing:process-renewals')->assertSuccessful();

        Queue::assertNothingPushed();
    });

    it('is still dispatched for a subscription that was not cancelled', function () {
        Queue::fake();

        activeSubscription(['end_date' => dueToday()]);

        $this->artisan('billing:process-renewals')->assertSuccessful();

        Queue::assertPushed(ProcessPlanRenewalJob::class);
    });

    it('is refused by the job even if it was already queued', function () {
        // O job pode ficar na fila enquanto o assinante cancela. Cobrar o cartão
        // depois de alguém pedir para parar é o pior desfecho possível aqui, então
        // a checagem é refeita na hora de executar — e antes de falar com o gateway.
        $userPlan = activeSubscription([
            'end_date' => dueToday(),
            'cancelled_at' => now(),
        ]);

        // Token de fachada só para o MpCustomerService construir. Ele nunca chega a
        // ser usado: o job para no cancelamento antes de tokenizar ou cobrar — se
        // não parasse, a chamada sairia para a rede com uma credencial inválida.
        config(['api-key.mercadopago.access_token' => 'TEST-not-a-real-token']);

        new ProcessPlanRenewalJob($userPlan->getKey())
            ->handle(app(MpCustomerService::class));

        // Uma renovação bem-sucedida abriria um UserPlan novo pelo subscribeToPlan.
        // Continuar com um só é a prova de que nada foi cobrado.
        expect(UserPlan::count())->toBe(1)
            ->and($userPlan->fresh()->cancelled_at)->not->toBeNull();
    });
});

describe('Resuming a subscription', function () {
    it('clears the cancellation', function () {
        $userPlan = activeSubscription(['cancelled_at' => now()]);

        $this->postJson('/api/v1/dashboard/signature/resume')->assertStatus(200);

        expect($userPlan->fresh()->isCancelled())->toBeFalse();
    });

    it('puts the subscription back in the billing run', function () {
        Queue::fake();

        $userPlan = activeSubscription([
            'end_date' => dueToday(),
            'cancelled_at' => now(),
        ]);

        $this->postJson('/api/v1/dashboard/signature/resume')->assertStatus(200);
        $this->artisan('billing:process-renewals')->assertSuccessful();

        Queue::assertPushed(ProcessPlanRenewalJob::class);
    });

    it('404s when nothing was cancelled', function () {
        activeSubscription();

        $this->postJson('/api/v1/dashboard/signature/resume')->assertStatus(404);
    });

    it('refuses to resume a subscription that already lapsed', function () {
        // Dentro da carência ainda há assinatura, mas o período acabou: não há o
        // que renovar, e reativar aqui prometeria uma cobrança que não vem.
        config(['api-key.grace_period_days' => 3]);

        activeSubscription([
            'start_date' => now()->subDays(30),
            'end_date' => now()->subHours(12),
            'cancelled_at' => now()->subDay(),
        ]);

        $this->postJson('/api/v1/dashboard/signature/resume')->assertStatus(422);
    });
});
