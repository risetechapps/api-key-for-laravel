<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;
use RiseTechApps\ApiKey\Tests\Support\FakeMercadoPagoHttpClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->gateway = new FakeMercadoPagoHttpClient;
    MercadoPagoConfig::setHttpClient($this->gateway);
    config(['api-key.mercadopago.access_token' => 'TEST-not-a-real-token']);
});

afterEach(function () {
    MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient);
});

function cardWith(array $attributes = []): UserCard
{
    return UserCard::create(array_merge([
        'authentication_id' => Authentication::factory()->create()->getKey(),
        'holder_name' => 'Fulano de Tal',
        'last_four' => '4321',
        'brand' => 'visa',
        'expiry_month' => '08',
        'expiry_year' => '2030',
    ], $attributes));
}

describe('Selecao das pendencias', function () {
    it('nao faz nada quando nao ha pendencia', function () {
        cardWith(['validation_payment_id' => '700100', 'validation_refunded_at' => now()]);

        $this->artisan('api-key:retry-validation-refunds')
            ->expectsOutputToContain('No pending validation refunds.')
            ->assertSuccessful();

        expect($this->gateway->requests)->toBeEmpty();
    });

    it('ignora cartao sem cobranca de validacao registrada', function () {
        // Cartões salvos antes desta coluna existir: não há payment_id para
        // estornar, e chutar um seria pior que não fazer nada.
        cardWith(['validation_payment_id' => null]);

        $this->artisan('api-key:retry-validation-refunds')
            ->expectsOutputToContain('No pending validation refunds.')
            ->assertSuccessful();
    });

    it('lista sem estornar no dry run', function () {
        cardWith(['validation_payment_id' => '700100']);

        $this->artisan('api-key:retry-validation-refunds', ['--dry-run' => true])
            ->expectsOutputToContain('700100')
            ->assertSuccessful();

        expect($this->gateway->requests)->toBeEmpty()
            ->and(UserCard::first()->validation_refunded_at)->toBeNull();
    });

    it('respeita o limite por execucao', function () {
        cardWith(['validation_payment_id' => '1']);
        cardWith(['validation_payment_id' => '2']);
        cardWith(['validation_payment_id' => '3']);

        $this->gateway->pushResponse(['id' => 1])->pushResponse(['id' => 2]);

        $this->artisan('api-key:retry-validation-refunds', ['--limit' => 2])->assertSuccessful();

        expect($this->gateway->requests)->toHaveCount(2)
            ->and(UserCard::whereNull('validation_refunded_at')->count())->toBe(1);
    });
});

describe('Reprocessamento', function () {
    it('estorna a pendencia e a marca como resolvida', function () {
        $card = cardWith(['validation_payment_id' => '700100']);
        $this->gateway->pushResponse(['id' => 555, 'status' => 'approved']);

        $this->artisan('api-key:retry-validation-refunds')
            ->expectsOutputToContain('Refunded 1 validation charge(s).')
            ->assertSuccessful();

        expect($card->fresh()->validation_refunded_at)->not->toBeNull()
            ->and($this->gateway->requests[0]->getUri())->toContain('/700100/refunds');
    });

    it('estorna o valor da validacao', function () {
        cardWith(['validation_payment_id' => '700100']);
        $this->gateway->pushResponse(['id' => 555]);

        $this->artisan('api-key:retry-validation-refunds')->assertSuccessful();

        expect($this->gateway->payload()['amount'])->toEqual(5);
    });

    it('aceita um valor diferente', function () {
        // O valor da cobrança de validação pode mudar; o comando não pode ficar
        // preso ao que estava em vigor quando a pendência nasceu.
        cardWith(['validation_payment_id' => '700100']);
        $this->gateway->pushResponse(['id' => 555]);

        $this->artisan('api-key:retry-validation-refunds', ['--amount' => '7.50'])->assertSuccessful();

        expect($this->gateway->payload()['amount'])->toEqual(7.5);
    });

    it('mantem a pendencia quando o estorno falha de novo', function () {
        $card = cardWith(['validation_payment_id' => '700100']);
        $this->gateway->pushFailure(['message' => 'still refusing']);

        $this->artisan('api-key:retry-validation-refunds')->assertFailed();

        // Continua marcada, então volta na próxima execução.
        expect($card->fresh()->validation_refunded_at)->toBeNull();
    });

    it('uma falha nao impede as outras pendencias', function () {
        // Cada pendência é um cliente diferente com dinheiro parado; abortar no
        // primeiro erro deixaria os demais esperando por tempo indeterminado.
        $primeiro = cardWith(['validation_payment_id' => '1']);
        $segundo = cardWith(['validation_payment_id' => '2']);

        $this->gateway->pushFailure(['message' => 'nope'])->pushResponse(['id' => 555]);

        $this->artisan('api-key:retry-validation-refunds')->assertFailed();

        expect($primeiro->fresh()->validation_refunded_at)->toBeNull()
            ->and($segundo->fresh()->validation_refunded_at)->not->toBeNull();
    });
});
