<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = Authentication::factory()->create();
});

describe('ApiKey Validation', function () {
    it('validates an active key', function () {
        $apiKey = ApiKey::factory()->create([
            'authentication_id' => $this->user->id,
            'active' => true,
        ]);

        $found = ApiKey::validateKey($apiKey->plainKey);

        expect($found)->not->toBeNull();
        expect($found->id)->toBe($apiKey->id);
    });

    it('finds a legacy key through the fallback and backfills its lookup hash', function () {
        $apiKey = ApiKey::factory()->create(['authentication_id' => $this->user->id]);
        $apiKey->forceFill(['lookup_hash' => null])->saveQuietly();

        expect(ApiKey::validateKey($apiKey->plainKey)?->id)->toBe($apiKey->id)
            ->and($apiKey->fresh()->lookup_hash)->not->toBeNull();
    });

    it('stops authenticating legacy keys once the fallback is turned off', function () {
        config(['api-key.legacy_scan.enabled' => false]);

        $apiKey = ApiKey::factory()->create(['authentication_id' => $this->user->id]);
        $apiKey->forceFill(['lookup_hash' => null])->saveQuietly();

        expect(ApiKey::validateKey($apiKey->plainKey))->toBeNull()
            ->and($apiKey->fresh()->lookup_hash)->toBeNull();
    });

    it('does not validate inactive keys', function () {
        $apiKey = ApiKey::factory()->inactive()->create([
            'authentication_id' => $this->user->id,
        ]);

        $found = ApiKey::validateKey($apiKey->plainKey);

        expect($found)->toBeNull();
    });

    it('does not validate expired keys', function () {
        $apiKey = ApiKey::factory()->expired()->create([
            'authentication_id' => $this->user->id,
        ]);

        $found = ApiKey::validateKey($apiKey->plainKey);

        expect($found)->toBeNull();
    });

    it('does not validate non-existent keys', function () {
        $found = ApiKey::validateKey('invalid-key-12345');

        expect($found)->toBeNull();
    });

    it('returns null for empty key', function () {
        $found = ApiKey::validateKey('');

        expect($found)->toBeNull();
    });
});

describe('Origin Validation', function () {
    it('allows any origin when no origins are set', function () {
        $apiKey = ApiKey::factory()->create([
            'allowed_origins' => [],
        ]);

        expect($apiKey->allowsRequest('https://example.com', null))->toBeTrue();
    });

    it('allows exact origin match', function () {
        $apiKey = ApiKey::factory()->create([
            'allowed_origins' => ['example.com'],
        ]);

        expect($apiKey->allowsRequest('https://example.com', null))->toBeTrue();
    });

    it('allows wildcard origin match', function () {
        $apiKey = ApiKey::factory()->create([
            'allowed_origins' => ['*.example.com'],
        ]);

        expect($apiKey->allowsRequest('https://sub.example.com', null))->toBeTrue();
    });

    it('rejects non-matching origin', function () {
        $apiKey = ApiKey::factory()->create([
            'allowed_origins' => ['example.com'],
        ]);

        expect($apiKey->allowsRequest('https://other.com', null))->toBeFalse();
    });

    it('trailing wildcard matches a single label', function () {
        $apiKey = ApiKey::factory()->create([
            'allowed_origins' => ['app.example.*'],
        ]);

        expect($apiKey->allowsRequest('https://app.example.com', null))->toBeTrue()
            ->and($apiKey->allowsRequest('https://app.example.org', null))->toBeTrue();
    });

    it('trailing wildcard does not match a third-party domain', function () {
        // "app.example.evil.com" começa com "app.example." mas é um domínio que
        // qualquer um registra. O curinga vale por um rótulo, não por qualquer
        // sufixo.
        $apiKey = ApiKey::factory()->create([
            'allowed_origins' => ['app.example.*'],
        ]);

        expect($apiKey->allowsRequest('https://app.example.evil.com', null))->toBeFalse()
            ->and($apiKey->allowsRequest('https://app.example.', null))->toBeFalse()
            ->and($apiKey->allowsRequest('https://app.examplex.com', null))->toBeFalse();
    });

    it('trailing wildcard on an IP prefix covers the rest of the address', function () {
        $apiKey = ApiKey::factory()->create([
            'allowed_origins' => ['192.168.*'],
        ]);

        expect($apiKey->allowsRequest(null, '192.168.0.15'))->toBeTrue()
            ->and($apiKey->allowsRequest('https://192.168.evil.com', null))->toBeFalse()
            ->and($apiKey->allowsRequest(null, '10.0.0.1'))->toBeFalse();
    });

    it('subdomain wildcard matches nested subdomains and the base domain only', function () {
        $apiKey = ApiKey::factory()->create([
            'allowed_origins' => ['*.example.com'],
        ]);

        expect($apiKey->allowsRequest('https://a.b.example.com', null))->toBeTrue()
            ->and($apiKey->allowsRequest('https://example.com', null))->toBeTrue()
            ->and($apiKey->allowsRequest('https://evilexample.com', null))->toBeFalse()
            ->and($apiKey->allowsRequest('https://example.com.evil.net', null))->toBeFalse();
    });

    it('is case insensitive for origins', function () {
        $apiKey = ApiKey::factory()->create([
            'allowed_origins' => ['EXAMPLE.COM'],
        ]);

        expect($apiKey->allowsRequest('https://example.com', null))->toBeTrue();
    });
});

describe('User Relationship', function () {
    it('belongs to a user', function () {
        $apiKey = ApiKey::factory()->create([
            'authentication_id' => $this->user->id,
        ]);

        expect($apiKey->authentication)->not->toBeNull();
        expect($apiKey->authentication->id)->toBe($this->user->id);
    });
});

describe('Key Hashing', function () {
    it('hashes the key before saving', function () {
        $plainKey = bin2hex(random_bytes(64));

        $apiKey = new ApiKey([
            'key' => $plainKey,
            'active' => true,
        ]);
        // authentication_id não está em $fillable (protegido de mass-assignment).
        $apiKey->authentication_id = $this->user->id;

        $apiKey->save();

        // The stored key should be hashed
        expect($apiKey->fresh()->key)->not->toBe($plainKey);
        // But we can still validate it
        expect(ApiKey::validateKey($plainKey))->not->toBeNull();
    });

    it('stores plain key temporarily during creation', function () {
        $plainKey = bin2hex(random_bytes(64));

        $apiKey = new ApiKey([
            'key' => $plainKey,
            'active' => true,
        ]);
        // authentication_id não está em $fillable (protegido de mass-assignment).
        $apiKey->authentication_id = $this->user->id;

        // Before saving, plainKey should be set
        expect($apiKey->plainKey)->toBe($plainKey);

        $apiKey->save();

        // After saving, it should still be available
        expect($apiKey->plainKey)->toBe($plainKey);
    });
});

describe('allowsRequest', function () {
    function keyWith(array $allowed): ApiKey
    {
        return ApiKey::factory()->make(['id' => (string) Str::uuid(), 'allowed_origins' => $allowed]);
    }

    it('allows everything with an empty list', function () {
        expect(keyWith([])->allowsRequest(null, '198.51.100.7'))->toBeTrue();
    });

    it('matches domain entries only against the Origin header', function () {
        $key = keyWith(['app.example.com', '*.example.org', 'api.example.*']);

        expect($key->allowsRequest('https://app.example.com', '198.51.100.7'))->toBeTrue()
            ->and($key->allowsRequest('https://x.example.org', null))->toBeTrue()
            ->and($key->allowsRequest('https://api.example.net', null))->toBeTrue()
            ->and($key->allowsRequest('https://evil.com', null))->toBeFalse()
            // Servidor sem Origin: lista só de domínios não tem o que conferir.
            ->and($key->allowsRequest(null, '198.51.100.7'))->toBeFalse();
    });

    it('matches IP entries only against the connection IP', function () {
        $key = keyWith(['203.0.113.5', '10.0.0.0/8', '192.168.*', '2001:db8::/32']);

        expect($key->allowsRequest(null, '203.0.113.5'))->toBeTrue()
            ->and($key->allowsRequest(null, '10.20.30.40'))->toBeTrue()
            ->and($key->allowsRequest(null, '192.168.1.9'))->toBeTrue()
            ->and($key->allowsRequest(null, '2001:db8::1'))->toBeTrue()
            ->and($key->allowsRequest(null, '198.51.100.7'))->toBeFalse()
            // Origin com o IP permitido, vindo de outro IP: recusado.
            ->and($key->allowsRequest('http://203.0.113.5', '198.51.100.7'))->toBeFalse()
            ->and($key->allowsRequest('http://[2001:db8::1]', '198.51.100.7'))->toBeFalse();
    });

    it('passes when either side matches on a mixed list', function () {
        $key = keyWith(['app.example.com', '203.0.113.5']);

        expect($key->allowsRequest('https://app.example.com', '198.51.100.7'))->toBeTrue()
            ->and($key->allowsRequest(null, '203.0.113.5'))->toBeTrue()
            ->and($key->allowsRequest('https://evil.com', '198.51.100.7'))->toBeFalse();
    });

    it('tells IP entries from domain entries', function () {
        expect(ApiKey::isIpEntry('203.0.113.5'))->toBeTrue()
            ->and(ApiKey::isIpEntry('10.0.0.0/8'))->toBeTrue()
            ->and(ApiKey::isIpEntry('192.168.*'))->toBeTrue()
            ->and(ApiKey::isIpEntry('::1'))->toBeTrue()
            ->and(ApiKey::isIpEntry('example.com'))->toBeFalse()
            ->and(ApiKey::isIpEntry('*.example.com'))->toBeFalse()
            ->and(ApiKey::isIpEntry('app.example.*'))->toBeFalse()
            ->and(ApiKey::isIpEntry('10.0.0.0/abc'))->toBeFalse();
    });
});
