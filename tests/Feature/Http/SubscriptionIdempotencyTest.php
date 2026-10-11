<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use RiseTechApps\ApiKey\Events\PlanChanged;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\PendingPayment\PendingPayment;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Services\PaymentOutcomeService;
use RiseTechApps\ApiKey\Tests\Support\FakeMercadoPagoHttpClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->gateway = new FakeMercadoPagoHttpClient;
    MercadoPagoConfig::setHttpClient($this->gateway);
    config(['api-key.mercadopago.access_token' => 'TEST-not-a-real-token']);

    $this->user = Authentication::factory()->create();
    $this->plan = Plan::factory()->create(['price' => 100.00, 'is_active' => true]);
});

afterEach(function () {
    MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient);
});

/** Pagamento aprovado como o PaymentClient o desserializa. */
function approvedPaymentFor(Authentication $user, Plan $plan, int $id = 7001, float $amount = 100.00): object
{
    return (object) [
        'id' => $id,
        'status' => 'approved',
        'status_detail' => 'accredited',
        'transaction_amount' => $amount,
        'external_reference' => $user->getKey().'|'.$plan->getKey(),
    ];
}

describe('subscribeToPlan com payment_id', function () {
    it('assina uma vez só quando o mesmo pagamento é confirmado duas vezes', function () {
        Event::fake([PlanChanged::class]);

        $first = $this->user->subscribeToPlan($this->plan, ['payment_id' => '7001']);
        $second = $this->user->subscribeToPlan($this->plan, ['payment_id' => '7001']);

        expect($first->wasRecentlyCreated)->toBeTrue()
            ->and($second->wasRecentlyCreated)->toBeFalse()
            ->and($second->getKey())->toBe($first->getKey())
            ->and(UserPlan::count())->toBe(1);

        // O e-mail de plano ativado sai do PlanChanged: duas assinaturas eram
        // dois e-mails para uma compra.
        Event::assertDispatchedTimes(PlanChanged::class, 1);
    });

    it('grava o payment_id junto com a assinatura, não num update separado', function () {
        $userPlan = $this->user->subscribeToPlan($this->plan, [
            'payment_id' => 7002,
            'payment_amount' => 90.00,
        ]);

        expect($userPlan->payment_id)->toBe('7002')
            ->and((float) $userPlan->fresh()->payment_amount)->toEqual(90.0);
    });

    it('completa na assinatura existente só os campos que faltam', function () {
        // O webhook chega primeiro e não sabe do crédito; o checkout chega depois
        // e sabe. O crédito não pode se perder, e o valor já gravado não muda.
        $this->user->subscribeToPlan($this->plan, ['payment_id' => '7003', 'payment_amount' => 80.00]);

        $again = $this->user->subscribeToPlan($this->plan, [
            'payment_id' => '7003',
            'payment_amount' => 999.00,
            'credit_applied' => 20.00,
        ]);

        $fresh = $again->fresh();

        expect((float) $fresh->credit_applied)->toEqual(20.0)
            ->and((float) $fresh->payment_amount)->toEqual(80.0);
    });

    it('sem payment_id continua criando uma assinatura por chamada', function () {
        $this->user->subscribeToPlan($this->plan);
        $this->user->subscribeToPlan($this->plan);

        expect(UserPlan::count())->toBe(2)
            ->and(UserPlan::where('active', true)->count())->toBe(1);
    });
});

describe('subscribeToPlan encerra a assinatura anterior', function () {
    it('desativa também a assinatura que está em carência', function () {
        // activePlan() exige end_date >= agora, então a assinatura em carência
        // ficava ativa ao lado da nova — típico da renovação, que roda perto do
        // fim do período.
        $old = UserPlan::factory()->inGracePeriod()->create([
            'authentication_id' => $this->user->getKey(),
            'plan_id' => $this->plan->getKey(),
        ]);

        $new = $this->user->subscribeToPlan($this->plan);

        expect($old->fresh()->active)->toBeFalse()
            ->and(UserPlan::where('authentication_id', $this->user->getKey())->where('active', true)->pluck('id')->all())
            ->toBe([$new->getKey()]);
    });

    it('desfaz tudo se a assinatura nova falhar no meio', function () {
        $old = UserPlan::factory()->create([
            'authentication_id' => $this->user->getKey(),
            'plan_id' => $this->plan->getKey(),
        ]);

        // No dispatcher da aplicação, e não via UserPlan::creating(): o
        // dispatcher é recriado a cada teste, o listener não vaza.
        Event::listen('eloquent.creating: '.UserPlan::class, fn () => throw new RuntimeException('boom'));

        expect(fn () => $this->user->subscribeToPlan($this->plan))->toThrow(RuntimeException::class);

        // Antes a anterior já tinha sido desativada quando o INSERT falhava, e o
        // usuário ficava sem assinatura nenhuma.
        expect($old->fresh()->active)->toBeTrue()
            ->and(UserPlan::count())->toBe(1);
    });
});

describe('Confirmação do pagamento pelo webhook', function () {
    it('não assina de novo quando o checkout já assinou o mesmo pagamento', function () {
        Event::fake([PlanChanged::class]);

        $this->user->subscribeToPlan($this->plan, ['payment_id' => '7001', 'payment_amount' => 100.00]);

        $applied = app(PaymentOutcomeService::class)->apply(approvedPaymentFor($this->user, $this->plan));

        expect($applied)->toBeFalse()
            ->and(UserPlan::count())->toBe(1);

        Event::assertDispatchedTimes(PlanChanged::class, 1);
    });

    it('o checkout que chega depois do webhook não duplica a assinatura', function () {
        app(PaymentOutcomeService::class)->apply(approvedPaymentFor($this->user, $this->plan));

        $this->gateway->pushResponse([
            'id' => 7001,
            'status' => 'approved',
            'status_detail' => 'accredited',
            'transaction_amount' => 100.00,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/dashboard/checkout/process', [
                'plan_id' => $this->plan->getKey(),
                'token' => 'card-token-1',
                'payment_method_id' => 'visa',
                'payer' => ['email' => 'buyer@example.com'],
            ])->assertStatus(200)->assertJsonPath('data.status', 'approved');

        expect(UserPlan::where('payment_id', '7001')->count())->toBe(1)
            ->and(UserPlan::where('active', true)->count())->toBe(1);
    });

    it('não reativa uma assinatura estornada quando o webhook é reentregue', function () {
        // A checagem antiga procurava a assinatura com active = true; depois do
        // estorno ela não achava nada e assinava de novo o pagamento devolvido.
        $userPlan = $this->user->subscribeToPlan($this->plan, ['payment_id' => '7001']);
        $userPlan->update(['active' => false, 'refunded_at' => now(), 'refund_id' => 'r1']);

        $applied = app(PaymentOutcomeService::class)->apply(approvedPaymentFor($this->user, $this->plan));

        expect($applied)->toBeFalse()
            ->and(UserPlan::count())->toBe(1)
            ->and(UserPlan::where('active', true)->exists())->toBeFalse();
    });

    it('leva o crédito do registro de espera para a assinatura', function () {
        PendingPayment::create([
            'authentication_id' => $this->user->getKey(),
            'plan_id' => $this->plan->getKey(),
            'payment_id' => '7001',
            'amount' => 70.00,
            'credit_applied' => 30.00,
            'status' => 'in_process',
        ]);

        app(PaymentOutcomeService::class)->apply(approvedPaymentFor($this->user, $this->plan, 7001, 70.00));

        $userPlan = UserPlan::where('payment_id', '7001')->first();

        expect((float) $userPlan->credit_applied)->toEqual(30.0)
            ->and((float) $userPlan->payment_amount)->toEqual(70.0);
    });
});

describe('Índice único em user_plans.payment_id', function () {
    it('o banco recusa duas assinaturas com o mesmo pagamento', function () {
        UserPlan::factory()->create(['payment_id' => '7001']);

        expect(fn () => UserPlan::factory()->create(['payment_id' => '7001']))
            ->toThrow(QueryException::class);
    });

    it('aceita várias assinaturas sem pagamento', function () {
        UserPlan::factory()->count(2)->create(['payment_id' => null]);

        expect(UserPlan::count())->toBe(2);
    });

    it('a migration para e lista os pagamentos repetidos em vez de apagar algum', function () {
        Schema::table('user_plans', fn (Blueprint $table) => $table->dropUnique(['payment_id']));

        UserPlan::factory()->count(2)->create(['payment_id' => '7001']);

        $migration = require __DIR__.'/../../../database/migrations/2026_10_09_000001_add_unique_payment_id_to_user_plans_table.php';

        expect(fn () => $migration->up())->toThrow(RuntimeException::class, '7001')
            ->and(DB::table('user_plans')->where('payment_id', '7001')->count())->toBe(2);
    });
});
