<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RiseTechApps\ApiKey\Enums\ApiError;
use RiseTechApps\ApiKey\Enums\BillingCycle;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Models\Webhook\WebhookDelivery;
use RiseTechApps\ApiKey\Models\Webhook\WebhookEndpoint;
use RiseTechApps\ApiKey\Services\WebhookDispatcher;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

/*
 * docs/openapi.yaml é escrito à mão. Estes testes amarram o documento ao código
 * para que um error_code, um status ou um campo do /usage novo não saia sem
 * aparecer nele.
 */

function openApiSpec(): array
{
    return Yaml::parseFile(__DIR__.'/../../docs/openapi.yaml');
}

/** @return array<string, mixed> */
function resolveRef(array $spec, array $node): array
{
    while (isset($node['$ref'])) {
        $path = explode('/', ltrim(substr($node['$ref'], 1), '/'));
        $node = array_reduce($path, fn ($carry, $key) => $carry[$key], $spec);
    }

    return $node;
}

it('lists exactly the error codes of ApiError', function () {
    $documented = openApiSpec()['components']['schemas']['ApiErrorCode']['enum'];

    expect($documented)->toEqualCanonicalizing(array_map(fn (ApiError $e) => $e->value, ApiError::cases()));
});

it('files each error code under the response of its HTTP status', function () {
    $spec = openApiSpec();
    $byStatus = [401 => 'Unauthorized', 402 => 'PaymentRequired', 403 => 'Forbidden', 429 => 'TooManyRequests'];

    foreach (ApiError::cases() as $error) {
        $response = $spec['components']['responses'][$byStatus[$error->status()]] ?? null;

        expect($response)->not->toBeNull("no response for HTTP {$error->status()}")
            ->and($response['content']['application/json']['examples'])
            ->toHaveKey($error->value);

        expect($response['content']['application/json']['examples'][$error->value]['value']['error_code'])
            ->toBe($error->value);
    }
});

it('documents the billing cycles the plan can have', function () {
    $documented = openApiSpec()['components']['schemas']['Usage']['properties']['plan']['properties']['billing_cycle']['enum'];

    expect(array_values(array_filter($documented)))->toEqualCanonicalizing(BillingCycle::values());
});

it('describes every field the /usage endpoint returns', function () {
    $user = Authentication::factory()->create();
    $plan = Plan::factory()->create(['request_limit' => 1000]);
    $key = ApiKey::factory()->create(['authentication_id' => $user->id, 'active' => true]);
    UserPlan::factory()->create(['authentication_id' => $user->id, 'plan_id' => $plan->id, 'active' => true]);

    $data = $this->withHeaders(['X-API-KEY' => $key->plainKey])->getJson('/api/v1/usage')->assertOk()->json('data');

    $spec = openApiSpec();
    $schema = $spec['components']['schemas']['Usage'];

    expect(array_keys($data))->toEqualCanonicalizing($schema['required']);

    foreach ($data as $section => $fields) {
        $properties = resolveRef($spec, $schema['properties'][$section])['properties'];

        expect(array_keys($fields))->toEqualCanonicalizing(array_keys($properties), "Usage.{$section}");
    }
});

it('documents the quota headers the middleware sends', function () {
    $headers = array_keys(openApiSpec()['components']['headers']);

    expect($headers)->toContain('X-Quota-Limit', 'X-Quota-Remaining', 'X-Quota-Used', 'X-Quota-Reset');
});

it('describes every field the /usage/daily endpoint returns', function () {
    $user = Authentication::factory()->create();
    $key = ApiKey::factory()->create(['authentication_id' => $user->id, 'active' => true]);
    UserPlan::factory()->create(['authentication_id' => $user->id, 'active' => true]);

    $data = $this->withHeaders(['X-API-KEY' => $key->plainKey])->getJson('/api/v1/usage/daily?days=2')->assertOk()->json('data');

    $schema = openApiSpec()['components']['schemas']['DailyUsage'];

    expect(array_keys($data))->toEqualCanonicalizing($schema['required'])
        ->and(array_keys($data['days'][0]))->toEqualCanonicalizing($schema['properties']['days']['items']['required']);
});

/** Propriedades de um schema, juntando as partes de um allOf. */
function schemaProperties(array $spec, array $schema): array
{
    $schema = resolveRef($spec, $schema);
    $properties = $schema['properties'] ?? [];

    foreach ($schema['allOf'] ?? [] as $part) {
        $properties += schemaProperties($spec, $part);
    }

    return $properties;
}

/** Schema do `data` de um webhook documentado. */
function webhookDataSchema(array $spec, string $event): array
{
    $allOf = $spec['webhooks'][$event]['post']['requestBody']['content']['application/json']['schema']['allOf'];

    return $allOf[1]['properties']['data'];
}

it('documents exactly the webhook events of the package', function () {
    expect(array_keys(openApiSpec()['webhooks']))->toEqualCanonicalizing(WebhookDispatcher::EVENTS);
});

it('documents the headers each webhook delivery carries', function () {
    $documented = array_column(openApiSpec()['components']['parameters'], 'name');

    expect($documented)->toContain('X-Webhook-Signature', 'X-Webhook-Timestamp', 'X-Webhook-Id', 'X-Webhook-Event');
});

it('describes the envelope and the data the webhooks really send', function () {
    config(['api-key.webhooks.block_private_networks' => false]);
    Http::fake(['hooks.example.com/*' => Http::response('ok', 200)]);

    $user = Authentication::factory()->create();
    $endpoint = WebhookEndpoint::create(['authentication_id' => $user->getKey(), 'url' => 'https://hooks.example.com/a', 'secret' => 'whsec_a', 'events' => null, 'active' => true]);

    $user->subscribeToPlan(Plan::factory()->create());
    Sanctum::actingAs($user);
    $this->postJson("/api/v1/dashboard/webhooks/{$endpoint->getKey()}/test")->assertOk();

    $spec = openApiSpec();
    $envelope = $spec['components']['schemas']['WebhookEnvelope'];

    foreach (WebhookDelivery::where('webhook_endpoint_id', $endpoint->getKey())->get() as $delivery) {
        expect(array_keys($delivery->payload))->toEqualCanonicalizing($envelope['required']);

        $documented = array_keys(schemaProperties($spec, webhookDataSchema($spec, $delivery->event)));
        expect(array_keys($delivery->payload['data']))->toEqualCanonicalizing($documented, $delivery->event);
    }

    expect(WebhookDelivery::pluck('event')->all())->toEqualCanonicalizing(['subscription.activated', 'webhook.test']);
});
