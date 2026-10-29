<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Models\Webhook\WebhookDelivery;
use RiseTechApps\ApiKey\Models\Webhook\WebhookEndpoint;
use RiseTechApps\ApiKey\Notifications\GracePeriodStartedNotification;

uses(RefreshDatabase::class);

// Sem Event::fake: o listener do e-mail e o do webhook rodam de verdade. Era
// o trecho que nenhum teste alcançava, porque o comando não estava agendado
// e o teste dele não conferia nada além do código de saída.
it('emails the owner and sends the webhook when a plan enters the grace period', function () {
    Notification::fake();
    config(['api-key.grace_period_days' => 3, 'api-key.webhooks.block_private_networks' => false]);
    Http::fake(['hooks.example.com/*' => Http::response('ok', 200)]);

    $user = Authentication::factory()->create();
    $userPlan = UserPlan::factory()->create([
        'authentication_id' => $user->id,
        'plan_id' => Plan::factory()->create()->id,
        'active' => true,
        'end_date' => now()->subHour(),
    ]);
    WebhookEndpoint::create([
        'authentication_id' => $user->id,
        'url' => 'https://hooks.example.com/api',
        'secret' => 'whsec_test',
        'events' => ['subscription.grace_period_started'],
        'active' => true,
    ]);

    $this->artisan('api-key:check-expired-plans', ['--grace-only' => true])->assertSuccessful();

    Notification::assertSentTo($user, GracePeriodStartedNotification::class);

    $delivery = WebhookDelivery::sole();
    expect($delivery->event)->toBe('subscription.grace_period_started')
        ->and($delivery->payload['data']['subscription_id'])->toBe($userPlan->id)
        ->and($delivery->payload['data']['grace_period_ends_at'])
        ->toBe($userPlan->fresh()->end_date->addDays(3)->format(DATE_ATOM));
});
