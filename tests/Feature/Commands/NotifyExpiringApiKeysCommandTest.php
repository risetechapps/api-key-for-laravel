<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use RiseTechApps\ApiKey\Events\ApiKeyExpiring;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Webhook\WebhookDelivery;
use RiseTechApps\ApiKey\Models\Webhook\WebhookEndpoint;
use RiseTechApps\ApiKey\Notifications\ApiKeyExpiringNotification;
use RiseTechApps\ApiKey\Services\AuthService;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    config(['api-key.api_keys.expiry_warning_days' => 7]);
    $this->user = Authentication::factory()->create();
});

function expiringKey(Authentication $user, ?DateTimeInterface $expiresAt, array $attributes = []): ApiKey
{
    return ApiKey::factory()->create([
        'authentication_id' => $user->id,
        'active' => true,
        'name' => 'Produção',
        'expires_at' => $expiresAt,
        ...$attributes,
    ]);
}

it('warns the owner about a key that expires inside the window', function () {
    $key = expiringKey($this->user, now()->addDays(3)->addHour());

    $this->artisan('api-key:notify-expiring')->assertSuccessful();

    Notification::assertSentTo($this->user, ApiKeyExpiringNotification::class, function ($n) use ($key) {
        return $n->apiKey->is($key) && $n->daysLeft === 4;
    });
});

it('leaves out keys outside the window, already expired, without expiry or inactive', function () {
    expiringKey($this->user, now()->addDays(30));
    expiringKey($this->user, now()->subDay());
    expiringKey($this->user, null);
    expiringKey($this->user, now()->addDay(), ['active' => false]);

    $this->artisan('api-key:notify-expiring')->assertSuccessful();

    Notification::assertNothingSent();
});

it('skips suspended accounts', function () {
    $this->user->forceFill(['status' => AuthService::$BLOCKED])->save();
    expiringKey($this->user, now()->addDay());

    $this->artisan('api-key:notify-expiring')->assertSuccessful();

    Notification::assertNothingSent();
});

it('warns once per key and expiry date', function () {
    $key = expiringKey($this->user, now()->addDays(2));

    $this->artisan('api-key:notify-expiring');
    $this->artisan('api-key:notify-expiring');

    Notification::assertSentToTimes($this->user, ApiKeyExpiringNotification::class, 1);

    // Validade estendida que volta a se aproximar: aviso novo.
    $key->update(['expires_at' => now()->addDays(5)]);
    $this->artisan('api-key:notify-expiring');

    Notification::assertSentToTimes($this->user, ApiKeyExpiringNotification::class, 2);
});

it('changes nothing on a dry run', function () {
    Event::fake([ApiKeyExpiring::class]);
    expiringKey($this->user, now()->addDay());

    $this->artisan('api-key:notify-expiring --dry-run')
        ->expectsOutputToContain('1 key(s) would be warned about.')
        ->assertSuccessful();

    Event::assertNotDispatched(ApiKeyExpiring::class);
});

it('does nothing when the warning is turned off', function () {
    config(['api-key.api_keys.expiry_warning_days' => 0]);
    expiringKey($this->user, now()->addDay());

    $this->artisan('api-key:notify-expiring')->assertSuccessful();

    Notification::assertNothingSent();
});

it('sends the api_key.expiring webhook without the secret', function () {
    config(['api-key.webhooks.block_private_networks' => false]);
    Http::fake(['hooks.example.com/*' => Http::response('ok', 200)]);

    WebhookEndpoint::create([
        'authentication_id' => $this->user->id,
        'url' => 'https://hooks.example.com/api',
        'secret' => 'whsec_test',
        'events' => ['api_key.expiring'],
        'active' => true,
    ]);
    $key = expiringKey($this->user, now()->addDays(2));

    $this->artisan('api-key:notify-expiring');

    $delivery = WebhookDelivery::sole();
    expect($delivery->event)->toBe('api_key.expiring')
        ->and($delivery->payload['data'])->toBe([
            'api_key_id' => $key->id,
            'name' => 'Produção',
            'expires_at' => $key->fresh()->expires_at->toIso8601String(),
            'days_left' => 2,
        ]);

    Http::assertSent(fn (HttpRequest $request) => ! str_contains($request->body(), (string) $key->fresh()->key));
});
