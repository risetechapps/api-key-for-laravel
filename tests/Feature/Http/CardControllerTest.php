<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    // O MpCustomerService fala com a API pelo cliente HTTP do Laravel, não pelo
    // SDK — daí os dois duplos convivendo neste arquivo.
    Http::fake([
        'api.mercadopago.com/v1/customers' => Http::response(['id' => 'cus_1'], 201),
        'api.mercadopago.com/v1/customers/*/cards' => Http::response(['id' => 'card_mp_1'], 201),
    ]);

    $this->user = Authentication::factory()->create();
    $this->actingAs($this->user, 'sanctum');
});

afterEach(function () {
    MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient);
});

/** Cobrança de validação aprovada, como o Mercado Pago devolve. */
function validationCharge(array $overrides = []): array
{
    return array_merge([
        'id' => 700100,
        'status' => 'approved',
        'status_detail' => 'accredited',
        'transaction_amount' => 5.00,
        'card' => [
            'last_four_digits' => '4321',
            'expiration_month' => 8,
            'expiration_year' => 2030,
        ],
    ], $overrides);
}

function storeCard(array $overrides = []): array
{
    return array_merge([
        'mp_token' => 'tok_1',
        'cpf' => '529.982.247-25',
        'payment_method_id' => 'visa',
        'holder_name' => 'Fulano de Tal',
        'brand' => 'visa',
    ], $overrides);
}

describe('Listing cards', function () {
    it('requires authentication', function () {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/dashboard/cards')->assertStatus(401);
    });

    it('shows only the cards of the signed-in user', function () {
        UserCard::create([
            'authentication_id' => $this->user->getKey(),
            'holder_name' => 'Meu Cartao', 'last_four' => '1111',
            'brand' => 'visa', 'expiry_month' => '01', 'expiry_year' => '2031',
        ]);
        UserCard::create([
            'authentication_id' => Authentication::factory()->create()->getKey(),
            'holder_name' => 'Cartao Alheio', 'last_four' => '2222',
            'brand' => 'visa', 'expiry_month' => '01', 'expiry_year' => '2031',
        ]);

        $response = $this->getJson('/api/v1/dashboard/cards')->assertStatus(200);

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.last_four'))->toBe('1111');
    });

    it('exposes the gateway card id so one-click payment works', function () {
        // O SDK do Mercado Pago tokeniza um cartao salvo com
        // createCardToken({ cardId, securityCode }) no navegador do dono. Sem
        // este campo na listagem, o checkout nunca reconhece o cartao como
        // vinculado e volta a pedir o numero completo a cada compra.
        UserCard::create([
            'authentication_id' => $this->user->getKey(),
            'holder_name' => 'Meu Cartao', 'last_four' => '1111',
            'brand' => 'visa', 'expiry_month' => '01', 'expiry_year' => '2031',
            'mp_customer_id' => 'cus_1', 'mp_card_id' => 'card_mp_1',
        ]);

        $card = $this->getJson('/api/v1/dashboard/cards')->assertStatus(200)->json('data.0');

        expect($card['mp_card_id'])->toBe('card_mp_1');
    });

    it('keeps the gateway customer id off the wire', function () {
        // Identifica o cliente no gateway e nenhuma tela precisa dele; a
        // listagem serializa o model cru, entao so o $hidden o segura.
        UserCard::create([
            'authentication_id' => $this->user->getKey(),
            'holder_name' => 'Meu Cartao', 'last_four' => '1111',
            'brand' => 'visa', 'expiry_month' => '01', 'expiry_year' => '2031',
            'mp_customer_id' => 'cus_secreto', 'mp_card_id' => 'card_mp_1',
        ]);

        $response = $this->getJson('/api/v1/dashboard/cards')->assertStatus(200);

        expect($response->json('data.0'))->not->toHaveKey('mp_customer_id')
            ->and($response->getContent())->not->toContain('cus_secreto');
    });

    it('serialises is_default as a boolean', function () {
        // Sem o cast o JSON devolvia o que o driver entregasse — bool no
        // PostgreSQL, inteiro no SQLite —, fazendo o contrato da API variar com
        // o banco.
        UserCard::create([
            'authentication_id' => $this->user->getKey(),
            'holder_name' => 'Meu Cartao', 'last_four' => '1111',
            'brand' => 'visa', 'expiry_month' => '01', 'expiry_year' => '2031',
            'is_default' => true,
        ]);

        expect($this->getJson('/api/v1/dashboard/cards')->json('data.0.is_default'))->toBeBool();
    });
});

describe('Saving a card', function () {
    it('validates the card, stores it and refunds the validation charge', function () {
        // Primeira resposta do SDK: a cobrança de R$5 de validação. Segunda: o
        // estorno dela.
        $this->gateway
            ->pushResponse(validationCharge())
            ->pushResponse(['id' => 900, 'status' => 'approved']);

        $this->postJson('/api/v1/dashboard/cards', storeCard())->assertStatus(201);

        $card = UserCard::where('authentication_id', $this->user->getKey())->first();

        expect($card)->not->toBeNull()
            ->and($card->last_four)->toBe('4321')
            ->and($card->mp_customer_id)->toBe('cus_1')
            ->and($card->mp_card_id)->toBe('card_mp_1')
            ->and($card->is_default)->toBeTrue();

        // A cobrança de validação não pode ficar no cartão do cliente.
        expect($this->gateway->requests)->toHaveCount(2)
            ->and($this->gateway->requests[1]->getUri())->toContain('/refunds');
    });

    it('charges only the validation amount', function () {
        $this->gateway->pushResponse(validationCharge())->pushResponse(['id' => 900]);

        $this->postJson('/api/v1/dashboard/cards', storeCard())->assertStatus(201);

        expect($this->gateway->payload()['transaction_amount'])->toEqual(5)
            ->and($this->gateway->payload()['external_reference'])
            ->toBe($this->user->getKey().'|card_validation');
    });

    it('sends the CPF stripped of punctuation', function () {
        $this->gateway->pushResponse(validationCharge())->pushResponse(['id' => 900]);

        $this->postJson('/api/v1/dashboard/cards', storeCard())->assertStatus(201);

        expect($this->gateway->payload()['payer']['identification']['number'])->toBe('52998224725');
    });

    it('sends the notification url on the validation charge too', function () {
        // A cobrança de validação é um pagamento como outro qualquer para o
        // Mercado Pago, e a revisão de qualidade olha todos.
        config(['api-key.mercadopago.notification_url' => 'https://exemplo.com/api/v1/dashboard/checkout/webhook']);

        $this->gateway->pushResponse(validationCharge())->pushResponse(['id' => 900]);

        $this->postJson('/api/v1/dashboard/cards', storeCard())->assertStatus(201);

        expect($this->gateway->payload()['notification_url'])
            ->toBe('https://exemplo.com/api/v1/dashboard/checkout/webhook');
    });

    it('forwards the device fingerprint', function () {
        $this->gateway->pushResponse(validationCharge())->pushResponse(['id' => 900]);

        $this->postJson('/api/v1/dashboard/cards', storeCard(['device_id' => 'armor.xyz789']))
            ->assertStatus(201);

        expect($this->gateway->header('X-meli-session-id'))->toBe('armor.xyz789');
    });

    it('forwards the idempotency key', function () {
        $this->gateway->pushResponse(validationCharge())->pushResponse(['id' => 900]);

        $this->postJson('/api/v1/dashboard/cards', storeCard(['idempotency_key' => 'card-key-1']))
            ->assertStatus(201);

        expect($this->gateway->header('X-Idempotency-Key'))->toBe('card-key-1');
    });

    it('saves nothing when the validation charge is declined', function () {
        $this->gateway->pushResponse(validationCharge([
            'status' => 'rejected',
            'status_detail' => 'cc_rejected_insufficient_amount',
        ]));

        $this->postJson('/api/v1/dashboard/cards', storeCard())->assertStatus(422);

        expect(UserCard::count())->toBe(0);
    });

    it('rejects a request missing the card fields', function () {
        $this->postJson('/api/v1/dashboard/cards', ['mp_token' => 'tok_1'])->assertStatus(422);

        expect($this->gateway->requests)->toBeEmpty();
    });

    it('keeps a single default card', function () {
        // Duas linhas com is_default marcam dois cartões preferidos e a renovação
        // passaria a depender da ordem em que o banco devolve.
        UserCard::create([
            'authentication_id' => $this->user->getKey(),
            'holder_name' => 'Antigo', 'last_four' => '1111',
            'brand' => 'visa', 'expiry_month' => '01', 'expiry_year' => '2031',
            'is_default' => true,
        ]);

        $this->gateway->pushResponse(validationCharge())->pushResponse(['id' => 900]);

        $this->postJson('/api/v1/dashboard/cards', storeCard())->assertStatus(201);

        expect(UserCard::where('is_default', true)->count())->toBe(1)
            ->and(UserCard::where('is_default', true)->value('last_four'))->toBe('4321');
    });

    it('updates the existing row when the same card is saved again', function () {
        UserCard::create([
            'authentication_id' => $this->user->getKey(),
            'holder_name' => 'Mesmo Cartao', 'last_four' => '4321',
            'brand' => 'visa', 'expiry_month' => '08', 'expiry_year' => '2030',
        ]);

        $this->gateway->pushResponse(validationCharge())->pushResponse(['id' => 900]);

        $this->postJson('/api/v1/dashboard/cards', storeCard())->assertStatus(201);

        expect(UserCard::where('authentication_id', $this->user->getKey())->count())->toBe(1);
    });

    it('still saves the card when the validation refund fails', function () {
        // O estorno é best-effort: falhar nele não pode desfazer um cartão que já
        // foi validado e associado ao cliente no gateway.
        $this->gateway->pushResponse(validationCharge())->pushFailure(['message' => 'refund refused']);

        $this->postJson('/api/v1/dashboard/cards', storeCard())->assertStatus(201);

        expect(UserCard::count())->toBe(1);
    });
});

describe('Deleting a card', function () {
    it('refuses to delete a card that belongs to someone else', function () {
        $other = UserCard::create([
            'authentication_id' => Authentication::factory()->create()->getKey(),
            'holder_name' => 'Alheio', 'last_four' => '2222',
            'brand' => 'visa', 'expiry_month' => '01', 'expiry_year' => '2031',
        ]);

        $this->deleteJson("/api/v1/dashboard/cards/{$other->getKey()}")->assertStatus(404);

        expect(UserCard::find($other->getKey()))->not->toBeNull();
    });

    it('deletes the card of the signed-in user', function () {
        $mine = UserCard::create([
            'authentication_id' => $this->user->getKey(),
            'holder_name' => 'Meu', 'last_four' => '1111',
            'brand' => 'visa', 'expiry_month' => '01', 'expiry_year' => '2031',
        ]);

        $this->deleteJson("/api/v1/dashboard/cards/{$mine->getKey()}")->assertStatus(200);

        expect(UserCard::find($mine->getKey()))->toBeNull();
    });
});
