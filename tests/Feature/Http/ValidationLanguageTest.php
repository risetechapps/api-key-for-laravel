<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function invalidReset(string $language)
{
    return test()->postJson('/api/v1/reset-password', [
        'token' => 'x',
        'email' => 'ana@example.com',
        'password' => 'password123',
        'password_confirmation' => 'other',
    ], ['Accept-Language' => $language]);
}

it('answers validation errors in portuguese for a portuguese client', function () {
    invalidReset('pt-BR')
        ->assertStatus(422)
        ->assertJsonPath('errors.password.0', 'A confirmação de senha não confere.');
});

it('answers the dashboard in portuguese even for an english client', function () {
    invalidReset('en')
        ->assertStatus(422)
        ->assertJsonPath('errors.password.0', 'A confirmação de senha não confere.');
});

it('lets the app translation win over the package fallback', function () {
    app('translator')->addLines(['validation.confirmed' => 'Texto do app.'], 'pt');

    invalidReset('pt-BR')->assertJsonPath('errors.password.0', 'Texto do app.');
});
