<?php

use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

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

        expect($apiKey->isOriginAllowed('https://example.com'))->toBeTrue();
    });

    it('allows exact origin match', function () {
        $apiKey = ApiKey::factory()->create([
            'allowed_origins' => ['example.com'],
        ]);

        expect($apiKey->isOriginAllowed('https://example.com'))->toBeTrue();
    });

    it('allows wildcard origin match', function () {
        $apiKey = ApiKey::factory()->create([
            'allowed_origins' => ['*.example.com'],
        ]);

        expect($apiKey->isOriginAllowed('https://sub.example.com'))->toBeTrue();
    });

    it('rejects non-matching origin', function () {
        $apiKey = ApiKey::factory()->create([
            'allowed_origins' => ['example.com'],
        ]);

        expect($apiKey->isOriginAllowed('https://other.com'))->toBeFalse();
    });

    it('is case insensitive for origins', function () {
        $apiKey = ApiKey::factory()->create([
            'allowed_origins' => ['EXAMPLE.COM'],
        ]);

        expect($apiKey->isOriginAllowed('https://example.com'))->toBeTrue();
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
            'authentication_id' => $this->user->id,
            'key' => $plainKey,
            'active' => true,
        ]);

        $apiKey->save();

        // The stored key should be hashed
        expect($apiKey->fresh()->key)->not->toBe($plainKey);
        // But we can still validate it
        expect(ApiKey::validateKey($plainKey))->not->toBeNull();
    });

    it('stores plain key temporarily during creation', function () {
        $plainKey = bin2hex(random_bytes(64));

        $apiKey = new ApiKey([
            'authentication_id' => $this->user->id,
            'key' => $plainKey,
            'active' => true,
        ]);

        // Before saving, plainKey should be set
        expect($apiKey->plainKey)->toBe($plainKey);

        $apiKey->save();

        // After saving, it should still be available
        expect($apiKey->plainKey)->toBe($plainKey);
    });
});
