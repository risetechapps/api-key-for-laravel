<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RiseTechApps\ApiKey\Support\Translation\BaseLocaleLoader;
use RiseTechApps\ApiKey\Support\Translation\ValidationFallback;

uses(RefreshDatabase::class);

// App em pt_BR, o padrão do Laravel no Brasil. O pacote traduz em `pt`.
beforeEach(function () {
    config(['app.locale' => 'pt_BR']);
    app()->setLocale('pt_BR');
    ValidationFallback::reset();

    Route::middleware('language')->get('/_locale', fn () => response()->json([
        'locale' => app()->getLocale(),
        'message' => __('api-key::messages.account_disabled'),
    ]));
});

it('traduz as mensagens do pacote num app em pt_BR, em vez da chave crua', function () {
    expect(__('api-key::messages.account_disabled'))
        ->not->toStartWith('api-key::')
        ->toBe(trans('api-key::messages.account_disabled', [], 'pt'));
});

it('usa o locale do app quando o cliente pede o mesmo idioma', function (string $header) {
    $this->getJson('/_locale', ['Accept-Language' => $header])
        ->assertJsonPath('locale', 'pt_BR')
        ->assertJsonPath('message', trans('api-key::messages.account_disabled', [], 'pt'));
})->with(['pt-BR', 'pt', 'pt-PT', 'pt-BR,pt;q=0.9,en;q=0.8']);

it('sem Accept-Language, responde em português, no locale do app', function () {
    $this->getJson('/_locale', ['Accept-Language' => ''])->assertJsonPath('locale', 'pt_BR');
});

it('responde em português mesmo para quem pede inglês', function () {
    $this->getJson('/_locale', ['Accept-Language' => 'en-US'])
        ->assertJsonPath('locale', 'pt_BR')
        ->assertJsonPath('message', trans('api-key::messages.account_disabled', [], 'pt'));
});

it('responde a validação em português num app em pt_BR sem lang/pt_BR próprio', function () {
    $this->postJson('/api/v1/reset-password', [
        'token' => 'x', 'email' => 'ana@example.com', 'password' => 'password123', 'password_confirmation' => 'other',
    ], ['Accept-Language' => 'pt-BR'])
        ->assertStatus(422)
        ->assertJsonPath('errors.password.0', 'A confirmação de senha não confere.');
});

it('deixa a tradução do app em pt_BR vencer a do pacote', function () {
    app('translator')->addLines(['messages.account_disabled' => 'Conta suspensa (texto do app).'], 'pt_BR', 'api-key');

    expect(__('api-key::messages.account_disabled'))->toBe('Conta suspensa (texto do app).');
});

it('reduz qualquer variante ao idioma base', function () {
    expect(BaseLocaleLoader::base('pt_BR'))->toBe('pt')
        ->and(BaseLocaleLoader::base('pt-BR'))->toBe('pt')
        ->and(BaseLocaleLoader::base('EN_us'))->toBe('en')
        ->and(BaseLocaleLoader::base('pt'))->toBe('pt');
});
