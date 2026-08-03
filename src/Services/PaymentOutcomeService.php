<?php

namespace RiseTechApps\ApiKey\Services;

use Illuminate\Support\Facades\Log;
use RiseTechApps\ApiKey\Events\PaymentRejected;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\PendingPayment\PendingPayment;
use RiseTechApps\ApiKey\Repositories\Coupon\CouponRepository;
use RiseTechApps\ApiKey\Repositories\Plan\PlanRepository;

/**
 * Aplica o desfecho de um pagamento do Mercado Pago.
 *
 * Dois caminhos chegam aqui e precisam decidir a mesma coisa: o webhook, que é o
 * normal, e o `api-key:reconcile-payments`, que existe porque o webhook falha de
 * formas fora do controle do pacote — `notification_url` ausente, servidor fora
 * do ar na hora da entrega, segredo trocado. Manter a regra num lugar só é o que
 * impede as duas versões de divergirem com o tempo, que é como um pagamento
 * acaba tratado de um jeito pelo webhook e de outro pela reconciliação.
 */
class PaymentOutcomeService
{
    public function __construct(
        protected readonly PlanRepository $planRepository,
        protected readonly CouponRepository $couponRepository,
    ) {}

    /**
     * @return bool Se o desfecho foi aplicado agora. Falso quando o pagamento
     *              ainda está em análise, quando já havia sido resolvido antes,
     *              ou quando a referência não é reconhecida.
     */
    public function apply(object $payment): bool
    {
        $status = (string) ($payment->status ?? '');

        if (in_array($status, ['rejected', 'cancelled'], true)) {
            return $this->reject($payment, $status);
        }

        if ($status === 'approved') {
            return $this->approve($payment, $status);
        }

        return false;
    }

    /**
     * Recusa: devolve a reserva do cupom e avisa o comprador.
     *
     * Só age quando existe registro de espera. Um pagamento recusado que nunca
     * ficou pendente teve a recusa mostrada na hora, na resposta do checkout, e
     * não tem cupom preso nem promessa a cumprir.
     */
    private function reject(object $payment, string $status): bool
    {
        $pending = PendingPayment::with(['authentication', 'plan', 'coupon'])
            ->where('payment_id', (string) $payment->id)
            ->first();

        // settle() é idempotente: reentrega do webhook, ou a reconciliação
        // passando depois dele, não pode devolver a reserva do cupom duas vezes.
        if (! $pending || ! $pending->settle(PendingPayment::OUTCOME_REJECTED, $status)) {
            return false;
        }

        if ($pending->coupon) {
            $this->couponRepository->releaseUse($pending->coupon);
        }

        Log::info('Pending payment settled as rejected', [
            'payment_id' => $payment->id,
            'user_id' => $pending->authentication_id,
            'plan_id' => $pending->plan_id,
            'status_detail' => $payment->status_detail ?? null,
            'coupon_released' => $pending->coupon !== null,
        ]);

        if ($pending->authentication && $pending->plan) {
            PaymentRejected::dispatch(
                $pending->authentication,
                $pending->plan,
                (string) $payment->id,
                (float) $pending->amount,
                $this->rejectionReason($payment),
                // O prefixo da referência é o que distingue renovação de compra
                // nova, e o texto do aviso muda por completo entre as duas.
                str_starts_with((string) ($payment->external_reference ?? ''), 'renewal|'),
            );
        }

        return true;
    }

    /**
     * Aprovação: assina o plano e registra o rastro do pagamento.
     */
    private function approve(object $payment, string $status): bool
    {
        if (! $payment->external_reference) {
            return false;
        }

        $reference = $this->parseExternalReference((string) $payment->external_reference);

        if (! $reference) {
            Log::warning('MP payment outcome: unrecognised external_reference', [
                'external_reference' => $payment->external_reference,
                'payment_id' => $payment->id,
            ]);

            return false;
        }

        // Cobrança de validação de cartão: não é compra de plano e não assina
        // ninguém.
        if ($reference['plan_id'] === 'card_validation') {
            return false;
        }

        $user = Authentication::find($reference['user_id']);

        // findById, e não findActiveById: o dinheiro já trocou de mãos. Um plano
        // tirado de venda entre a compra e a confirmação ainda tem de ser
        // entregue a quem pagou por ele — só *adquirir* depende de is_active.
        $plan = $this->planRepository->findById($reference['plan_id']);

        if (! $user || ! $plan) {
            return false;
        }

        PendingPayment::where('payment_id', (string) $payment->id)
            ->first()
            ?->settle(PendingPayment::OUTCOME_APPROVED, $status);

        $alreadySubscribed = $user->userPlan()
            ->where('plan_id', $plan->getKey())
            ->where('active', true)
            ->where('payment_id', (string) $payment->id)
            ->exists();

        if ($alreadySubscribed) {
            return false;
        }

        $userPlan = $user->subscribeToPlan($plan);

        // O checkout direto grava estes campos; o caminho do webhook não gravava,
        // e assinaturas confirmadas por webhook ficavam sem rastro de pagamento,
        // invisíveis para a tela de estorno, que filtra por payment_id.
        $userPlan->update([
            'payment_id' => (string) $payment->id,
            'payment_amount' => (float) $payment->transaction_amount,
        ]);

        return true;
    }

    /**
     * Motivo da recusa em texto para o comprador, ou null quando o gateway não
     * informou nada que valha repassar.
     */
    private function rejectionReason(object $payment): ?string
    {
        $detail = (string) ($payment->status_detail ?? '');

        if ($detail === '') {
            return null;
        }

        $key = "api-key::messages.payment_rejected.{$detail}";
        $message = __($key);

        // __() devolve a própria chave quando não há entrada, que é como um
        // status_detail não mapeado se revela.
        return $message === $key
            ? __('api-key::messages.payment_rejected.default')
            : $message;
    }

    /**
     * Divide o external_reference do Mercado Pago.
     *
     * Dois formatos são emitidos pelo pacote:
     *   "<userId>|<planId>"                        — checkout e validação de cartão
     *   "renewal|<userId>|<planId>|<userPlanId>"   — renovação automática
     *
     * @return array{user_id: string, plan_id: string}|null
     */
    private function parseExternalReference(string $reference): ?array
    {
        // explode nunca devolve lista vazia, entao o indice 0 sempre existe: so
        // os demais precisam de isset.
        $parts = explode('|', $reference);

        if ($parts[0] === 'renewal') {
            return isset($parts[1], $parts[2])
                ? ['user_id' => $parts[1], 'plan_id' => $parts[2]]
                : null;
        }

        return isset($parts[1]) && $parts[0] !== ''
            ? ['user_id' => $parts[0], 'plan_id' => $parts[1]]
            : null;
    }
}
