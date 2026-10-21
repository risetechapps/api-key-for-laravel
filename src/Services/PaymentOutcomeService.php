<?php

namespace RiseTechApps\ApiKey\Services;

use RiseTechApps\ApiKey\Events\PaymentRejected;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\PendingPayment\PendingPayment;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
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

        logglyInfo()->withContext([
            'payment_id' => $payment->id,
            'user_id' => $pending->authentication_id,
            'plan_id' => $pending->plan_id,
            'status_detail' => $payment->status_detail ?? null,
            'coupon_released' => $pending->coupon !== null,
        ])->log('Pending payment settled as rejected');

        // Excedente recusado (PIX que expirou, por exemplo) não é compra de
        // plano: o aviso de "pagamento do plano não aprovado" seria falso.
        $type = $this->parseExternalReference((string) ($payment->external_reference ?? ''))['type'] ?? null;

        if ($type === 'overage') {
            return true;
        }

        if ($pending->authentication && $pending->plan) {
            PaymentRejected::dispatch(
                $pending->authentication,
                $pending->plan,
                (string) $payment->id,
                (float) $pending->amount,
                $this->rejectionReason($payment),
                // O prefixo da referência é o que distingue renovação de compra
                // nova, e o texto do aviso muda por completo entre as duas.
                $type === 'renewal',
            );
        }

        return true;
    }

    /**
     * Aprovação: assina o plano e registra o rastro do pagamento.
     */
    private function approve(object $payment, string $status): bool
    {
        // Gateway que limita o tamanho da referência (ou não a devolve) pode
        // trazer só o id: a compra é achada pelo registro de espera.
        $reference = $payment->external_reference
            ? $this->parseExternalReference((string) $payment->external_reference)
            : null;

        $reference ??= $this->referenceFromPending((string) $payment->id);

        if (! $reference) {
            if (! $payment->external_reference) {
                return false;
            }

            logglyWarning()->withContext([
                'external_reference' => $payment->external_reference,
                'payment_id' => $payment->id,
            ])->log('MP payment outcome: unrecognised external_reference');

            return false;
        }

        // Cobrança de validação de cartão: não é compra de plano e não assina
        // ninguém.
        if ($reference['plan_id'] === 'card_validation') {
            return false;
        }

        // Excedente: quita o período, não assina nada.
        if ($reference['type'] === 'overage') {
            PendingPayment::where('payment_id', (string) $payment->id)->first()
                ?->settle(PendingPayment::OUTCOME_APPROVED, $status);

            return OverageBillingService::markPaid((string) $reference['user_plan_id'], (string) $payment->id);
        }

        $user = Authentication::find($reference['user_id']);

        // findById, e não findActiveById: o dinheiro já trocou de mãos. Um plano
        // tirado de venda entre a compra e a confirmação ainda tem de ser
        // entregue a quem pagou por ele — só *adquirir* depende de is_active.
        $plan = $this->planRepository->findById($reference['plan_id']);

        if (! $user || ! $plan) {
            return false;
        }

        $pending = PendingPayment::where('payment_id', (string) $payment->id)->first();
        $pending?->settle(PendingPayment::OUTCOME_APPROVED, $status);

        // Idempotente por payment_id: reentrega do webhook, a reconciliação ou o
        // próprio checkout chegando depois não assinam de novo. A checagem antiga
        // exigia `active = true`, então um webhook reentregue depois de um
        // estorno ou cancelamento reativava a assinatura devolvida.
        //
        // credit_applied vem do registro de espera: o checkout o calculou antes
        // de o pagamento ficar em análise, e sem ele o crédito usado na compra
        // ficava sem rastro quando quem confirmava era o webhook.
        $userPlan = $user->subscribeToPlan($plan, [
            'payment_id' => (string) $payment->id,
            'payment_amount' => (float) $payment->transaction_amount,
            'credit_applied' => $pending?->credit_applied,
            'payment_method' => ($payment->payment_method_id ?? null) === 'pix' ? 'pix' : 'card',
        ]);

        return $userPlan->wasRecentlyCreated;
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
     * Compra registrada como espera (PIX, cartão em análise) quando a
     * referência do gateway não diz de quem é.
     *
     * @return array{type: string, user_id: string, plan_id: string, user_plan_id: ?string}|null
     */
    private function referenceFromPending(string $paymentId): ?array
    {
        if ($paymentId === '') {
            return null;
        }

        // Excedente também fica em espera, com o plano da assinatura: tratado
        // como compra, assinaria o plano de novo.
        if ($userPlan = UserPlan::where('overage_payment_id', $paymentId)->first()) {
            return ['type' => 'overage', 'user_id' => (string) $userPlan->authentication_id, 'plan_id' => (string) $userPlan->plan_id, 'user_plan_id' => (string) $userPlan->getKey()];
        }

        $pending = PendingPayment::where('payment_id', $paymentId)->first();

        return $pending && $pending->authentication_id && $pending->plan_id
            ? ['type' => 'checkout', 'user_id' => (string) $pending->authentication_id, 'plan_id' => (string) $pending->plan_id, 'user_plan_id' => null]
            : null;
    }

    /**
     * Divide o external_reference do Mercado Pago.
     *
     * Dois formatos são emitidos pelo pacote:
     *   "<userId>|<planId>"                        — checkout e validação de cartão
     *   "renewal|<userId>|<planId>|<userPlanId>"   — renovação automática
     *   "overage|<userId>|<planId>|<userPlanId>"   — excedente do período
     *   "rn-<userPlanId>" / "ov-<userPlanId>"      — renovação e excedente
     *                                                pelos Pagamentos Automáticos.
     *                                                A Orders API aceita até 64
     *                                                caracteres, sem `|`, então
     *                                                o resto vem da assinatura.
     *
     * @return array{type: string, user_id: string, plan_id: string, user_plan_id: ?string}|null
     */
    private function parseExternalReference(string $reference): ?array
    {
        // explode nunca devolve lista vazia, entao o indice 0 sempre existe: so
        // os demais precisam de isset.
        if (preg_match('/^(rn|ov)-(.+)$/', $reference, $m)) {
            $userPlan = UserPlan::find($m[2]);

            return $userPlan
                ? ['type' => $m[1] === 'rn' ? 'renewal' : 'overage', 'user_id' => (string) $userPlan->authentication_id, 'plan_id' => (string) $userPlan->plan_id, 'user_plan_id' => (string) $userPlan->getKey()]
                : null;
        }

        $parts = explode('|', $reference);

        if (in_array($parts[0], ['renewal', 'overage'], true)) {
            return isset($parts[1], $parts[2])
                ? ['type' => $parts[0], 'user_id' => $parts[1], 'plan_id' => $parts[2], 'user_plan_id' => $parts[3] ?? null]
                : null;
        }

        return isset($parts[1]) && $parts[0] !== ''
            ? ['type' => 'checkout', 'user_id' => $parts[0], 'plan_id' => $parts[1], 'user_plan_id' => null]
            : null;
    }
}
