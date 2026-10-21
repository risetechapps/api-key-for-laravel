<?php

use RiseTechApps\ApiKey\Models\Authentication\Authentication;

describe('Service provider', function () {
    it('makes Authentication the user model', function () {
        expect(config('auth.providers.users.model'))->toBe(Authentication::class);
    });
});
