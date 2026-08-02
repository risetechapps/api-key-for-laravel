<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Coupon\Coupon;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Tests\Support\FakeMercadoPagoHttpClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->gateway = new FakeMercadoPagoHttpClient;
    MercadoPagoConfig::setHttpClient($this->gateway);
    config(['api-key.mercadopago.access_token' => 'TEST-not-a-real-token']);

    $this->user = Authentication::factory()->create();
    $this->plan = Plan::factory()->create(['price' => 100.00, 'is_active' => true]);
    $this->actingAs($this->user, 'sanctum');
});

afterEach(function () {
    // O transporte do SDK é um singleton global: deixá-lo apontando para o duplo
    // vazaria para qualquer teste que rodasse depois neste processo.
    MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient);
});

/** Payload mínimo de cartão que passa pelas validações do process(). */
function cardPayload(array $overrides = []): array
{
    return array_merge([
        'token' => 'card-token-1',
        'payment_method_id' => 'visa',
        'payer' => ['email' => 'buyer@example.com'],
    ], $overrides);
}

/** Corpo de um pagamento como o Mercado Pago devolve. */
function mpPayment(array $overrides = []): array
{
    return array_merge([
        'id' => 123456789,
        'status' => 'approved',
        'status_detail' => 'accredited',
        'transaction_amount' => 100.00,
    ], $overrides);
}

describe('Coupon preview', function () {
    it('404s for a plan that is not on sale', function () {
        // Mesma regra do process(): o preview resolve por findActiveById, senão
        // cotaria um plano que o checkout depois recusa.
        $inactive = Plan::factory()->inactive()->create();

        $this->postJson('/api/v1/dashboard/checkout/coupon', [
            'code' => 'ANY',
            'plan_id' => $inactive->getKey(),
        ])->assertStatus(404);
    });

    it('422s an expired coupon', function () {
        $coupon = Coupon::factory()->expired()->create();

        $this->postJson('/api/v1/dashboard/checkout/coupon', [
            'code' => $coupon->code,
            'plan_id' => $this->plan->getKey(),
        ])->assertStatus(422);
    });

    it('quotes the same total the card will be charged', function () {
        // O defeito que isto guarda: a tela cotava preço menos cupom e o cartão
        // era debitado preço menos cupom menos crédito. Preview e cobrança têm
        // de aplicar a mesma ordem — preço, cupom, crédito.
        $current = Plan::factory()->create(['price' => 60.00]);
        UserPlan::factory()->create([
            'authentication_id' => $this->user->getKey(),
            'plan_id' => $current->getKey(),
            'active' => true,
            'start_date' => now()->subDays(15),
            'end_date' => now()->addDays(15),
        ]);

        $coupon = Coupon::factory()->create(['type' => 'percentage', 'value' => 10]);

        $response = $this->postJson('/api/v1/dashboard/checkout/coupon', [
            'code' => $coupon->code,
            'plan_id' => $this->plan->getKey(),
        ])->assertStatus(200);

        // 10% sobre os 100 do plano novo = 10 de desconto; metade dos 60 do plano
        // corrente ainda não foi usada = ~30 de crédito.
        $credit = $response->json('data.credit');

        expect($response->json('data.discount'))->toEqual(10)
            ->and($credit)->toBeGreaterThan(29.0)
            ->and($response->json('data.final_price'))->toEqual(round(100.0 - 10.0 - $credit, 2));
    });

    it('never quotes a negative price', function () {
        $coupon = Coupon::factory()->create(['type' => 'fixed', 'value' => 500]);

        $this->postJson('/api/v1/dashboard/checkout/coupon', [
            'code' => $coupon->code,
            'plan_id' => $this->plan->getKey(),
        ])->assertStatus(200)->assertJsonPath('data.final_price', 0);
    });
});

describe('Checkout, before the gateway', function () {
    it('404s a plan that does not exist', function () {
        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => (string) Str::uuid(),
            ...cardPayload(),
        ])->assertStatus(404);

        expect($this->gateway->requests)->toBeEmpty();
    });

    it('404s a plan that was taken off sale', function () {
        // O plan_id viaja no corpo, então filtrar is_active só na listagem do
        // catálogo deixava qualquer um assinar um plano fora de venda.
        $inactive = Plan::factory()->inactive()->create(['price' => 10.00]);

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $inactive->getKey(),
            ...cardPayload(),
        ])->assertStatus(404);

        expect($this->gateway->requests)->toBeEmpty()
            ->and(UserPlan::count())->toBe(0);
    });

    it('422s without a card token', function () {
        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            'payment_method_id' => 'visa',
            'payer' => ['email' => 'buyer@example.com'],
        ])->assertStatus(422);

        expect($this->gateway->requests)->toBeEmpty();
    });

    it('requires authentication', function () {
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            ...cardPayload(),
        ])->assertStatus(401);
    });

    it('activates without charging when the total reaches zero', function () {
        // Cupom de 100%: não há o que cobrar, e chamar o gateway com valor zero
        // só produziria uma recusa.
        $coupon = Coupon::factory()->create(['type' => 'percentage', 'value' => 100]);

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            'coupon_code' => $coupon->code,
            ...cardPayload(),
        ])->assertStatus(200)->assertJsonPath('data.status', 'approved');

        expect($this->gateway->requests)->toBeEmpty()
            ->and($this->user->fresh()->hasActivePlan())->toBeTrue();
    });
});

describe('Checkout, at the gateway', function () {
    it('subscribes the buyer and records the payment trail', function () {
        $this->gateway->pushResponse(mpPayment(['id' => 987654321]));

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            ...cardPayload(),
        ])->assertStatus(200)->assertJsonPath('data.status', 'approved');

        $userPlan = UserPlan::where('authentication_id', $this->user->getKey())->first();

        // payment_id é o que liga a assinatura ao pagamento na tela de estorno;
        // sem ele a compra fica invisível para o admin.
        expect($userPlan)->not->toBeNull()
            ->and((string) $userPlan->payment_id)->toBe('987654321')
            ->and((float) $userPlan->payment_amount)->toBe(100.00);
    });

    it('charges the plan price when no discount applies', function () {
        $this->gateway->pushResponse(mpPayment());

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            ...cardPayload(),
        ])->assertStatus(200);

        expect($this->gateway->payload()['transaction_amount'])->toEqual(100);
    });

    it('applies the coupon to the full price and only then the credit', function () {
        // A ordem importa: 10% sobre 100 são 10. Se o crédito entrasse antes, o
        // percentual incidiria sobre o valor já creditado e o comprador receberia
        // um desconto menor do que o anunciado.
        $current = Plan::factory()->create(['price' => 60.00]);
        UserPlan::factory()->create([
            'authentication_id' => $this->user->getKey(),
            'plan_id' => $current->getKey(),
            'active' => true,
            'start_date' => now()->subDays(15),
            'end_date' => now()->addDays(15),
        ]);

        $coupon = Coupon::factory()->create(['type' => 'percentage', 'value' => 10]);

        $this->gateway->pushResponse(mpPayment());

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            'coupon_code' => $coupon->code,
            ...cardPayload(),
        ])->assertStatus(200);

        $charged = $this->gateway->payload()['transaction_amount'];

        // 100 - 10 (cupom) - ~30 (metade do período de 60) ≈ 60.
        expect($charged)->toBeGreaterThan(59.0)
            ->and($charged)->toBeLessThan(61.0);

        $userPlan = UserPlan::where('authentication_id', $this->user->getKey())
            ->where('plan_id', $this->plan->getKey())
            ->first();

        // credit_applied separa crédito de troca de desconto de cupom; sem a
        // coluna os dois somem no mesmo total e o histórico deixa de fechar.
        expect((float) $userPlan->credit_applied)->toBeGreaterThan(29.0);
    });

    it('sends the notification url when it is configured', function () {
        // A revisão de qualidade do Mercado Pago cobra `notification_url` no
        // corpo do pagamento; cadastrar a URL no painel não satisfaz a checagem.
        config(['api-key.mercadopago.notification_url' => 'https://exemplo.com/api/v1/dashboard/checkout/webhook']);

        $this->gateway->pushResponse(mpPayment());

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            ...cardPayload(),
        ])->assertStatus(200);

        expect($this->gateway->payload()['notification_url'])
            ->toBe('https://exemplo.com/api/v1/dashboard/checkout/webhook');
    });

    it('omits the notification url when it is not configured', function () {
        // O gateway valida a URL e recusa o pagamento se ela não for HTTPS
        // pública. Mandar um valor vazio ou localhost derrubaria todo checkout
        // em desenvolvimento.
        config(['api-key.mercadopago.notification_url' => null]);

        $this->gateway->pushResponse(mpPayment());

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            ...cardPayload(),
        ])->assertStatus(200);

        expect($this->gateway->payload())->not->toHaveKey('notification_url');
    });

    it('forwards the device fingerprint as a gateway header', function () {
        // Sinal de antifraude coletado no navegador. Sem ele a análise de risco
        // decide com menos informação e recusas high_risk ficam mais frequentes.
        $this->gateway->pushResponse(mpPayment());

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            'device_id' => 'armor.abc123',
            ...cardPayload(),
        ])->assertStatus(200);

        expect($this->gateway->header('X-meli-session-id'))->toBe('armor.abc123');
    });

    it('omits the device header when the browser did not collect one', function () {
        // Bloqueador de script ou rede lenta não podem impedir o pagamento; o
        // header some e o resto segue igual.
        $this->gateway->pushResponse(mpPayment());

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            ...cardPayload(),
        ])->assertStatus(200);

        expect($this->gateway->header('X-meli-session-id'))->toBeNull();
    });

    it('forwards the idempotency key the client sent', function () {
        $this->gateway->pushResponse(mpPayment());

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            'idempotency_key' => 'client-key-abc',
            ...cardPayload(),
        ])->assertStatus(200);

        expect($this->gateway->header('X-Idempotency-Key'))->toBe('client-key-abc');
    });

    it('derives a stable key when the client sends none', function () {
        // Sem chave do cliente, duas submissões do mesmo formulário (mesmo token,
        // mesmo valor) têm de chegar ao gateway com a mesma chave — é o que
        // impede a segunda de virar uma cobrança nova.
        //
        // As duas tentativas são recusadas de propósito: se a primeira fosse
        // aprovada, a assinatura recém-criada geraria crédito pró-rata quase
        // integral e a segunda zeraria o total sem chegar ao gateway. Recusa
        // seguida de retry é, aliás, o caso em que o comprador reenvia mesmo.
        $declined = mpPayment(['status' => 'rejected', 'status_detail' => 'cc_rejected_call_for_authorize']);
        $this->gateway->pushResponse($declined)->pushResponse($declined);

        $body = ['plan_id' => $this->plan->getKey(), ...cardPayload()];

        $this->postJson('/api/v1/dashboard/checkout/process', $body)->assertStatus(422);
        $this->postJson('/api/v1/dashboard/checkout/process', $body)->assertStatus(422);

        expect($this->gateway->header('X-Idempotency-Key', 0))
            ->toBe($this->gateway->header('X-Idempotency-Key', 1))
            ->and($this->gateway->header('X-Idempotency-Key', 0))->not->toBeEmpty();
    });

    it('keeps the coupon claimed while the payment is still pending', function () {
        // pending/in_process ainda podem virar assinatura pelo webhook, então a
        // reserva do cupom não pode ser devolvida.
        $coupon = Coupon::factory()->create(['max_uses' => 1, 'uses' => 0]);

        $this->gateway->pushResponse(mpPayment(['status' => 'in_process', 'status_detail' => 'pending_review']));

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            'coupon_code' => $coupon->code,
            ...cardPayload(),
        ])->assertStatus(200)->assertJsonPath('data.status', 'pending');

        expect($coupon->fresh()->uses)->toBe(1);
    });

    it('hands the coupon back when the card is declined', function () {
        // O ponto do release: a última vaga de um cupom não pode ser queimada por
        // um cartão recusado.
        $coupon = Coupon::factory()->create(['max_uses' => 1, 'uses' => 0]);

        $this->gateway->pushResponse(mpPayment(['status' => 'rejected', 'status_detail' => 'cc_rejected_insufficient_amount']));

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            'coupon_code' => $coupon->code,
            ...cardPayload(),
        ])->assertStatus(422);

        expect($coupon->fresh()->uses)->toBe(0)
            ->and(UserPlan::count())->toBe(0);
    });

    it('hands the coupon back when the gateway itself errors', function () {
        $coupon = Coupon::factory()->create(['max_uses' => 1, 'uses' => 0]);

        $this->gateway->pushFailure(['message' => 'invalid_token', 'status_detail' => 'cc_rejected_bad_filled_security_code']);

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            'coupon_code' => $coupon->code,
            ...cardPayload(),
        ])->assertStatus(422);

        expect($coupon->fresh()->uses)->toBe(0)
            ->and(UserPlan::count())->toBe(0);
    });

    it('does not subscribe anyone on a declined payment', function () {
        $this->gateway->pushResponse(mpPayment(['status' => 'rejected', 'status_detail' => 'cc_rejected_call_for_authorize']));

        $this->postJson('/api/v1/dashboard/checkout/process', [
            'plan_id' => $this->plan->getKey(),
            ...cardPayload(),
        ])->assertStatus(422);

        expect($this->user->fresh()->hasActivePlan())->toBeFalse();
    });
});
