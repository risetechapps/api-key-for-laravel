<?php

namespace RiseTechApps\ApiKey\Services;

use Illuminate\Support\Carbon;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

/**
 * Decide se um cancelamento dá direito a estorno.
 *
 * Duas comportas, ambas configuráveis em `api-key.refund`: a assinatura precisa
 * estar dentro da janela de arrependimento e o assinante não pode ter consumido
 * mais que o teto de requisições do ciclo. A segunda existe porque a primeira
 * sozinha entrega o produto de graça — bastaria assinar, esgotar a cota nos
 * primeiros dias e pedir o dinheiro de volta.
 *
 * A avaliação é separada da execução de propósito: o controller consulta o
 * veredito antes de falar com o gateway, e o mesmo serviço serve para a tela
 * mostrar ao cliente o que vai acontecer antes de ele confirmar.
 */
class RefundPolicy
{
    public function decide(UserPlan $userPlan): RefundDecision
    {
        $windowDays = (int) config('api-key.refund.window_days', 0);

        // Desligado é o padrão. Estorno automático não pode ser comportamento
        // herdado por quem apenas atualizou o pacote.
        if ($windowDays <= 0) {
            return RefundDecision::refused(RefundDecision::REASON_DISABLED);
        }

        if ($userPlan->refunded_at !== null) {
            return RefundDecision::refused(RefundDecision::REASON_ALREADY_REFUNDED);
        }

        $amount = (float) ($userPlan->payment_amount ?? 0);

        // Sem pagamento registrado não há o que devolver: plano gratuito,
        // assinatura coberta por cupom de 100% ou por crédito de troca.
        if ($userPlan->payment_id === null || $amount <= 0) {
            return RefundDecision::refused(RefundDecision::REASON_NOT_PAID);
        }

        if (! $this->withinWindow($userPlan, $windowDays)) {
            return RefundDecision::refused(RefundDecision::REASON_WINDOW_EXPIRED);
        }

        if ($this->usageExceeded($userPlan)) {
            return RefundDecision::refused(RefundDecision::REASON_USAGE_EXCEEDED);
        }

        return RefundDecision::eligible($amount);
    }

    /**
     * A janela conta da primeira vez que este assinante contratou este plano.
     *
     * Usar o `start_date` da assinatura corrente reabriria o direito a cada
     * renovação automática, e o ciclo assinar-usar-cancelar se repetiria todo
     * mês sem o cliente nunca pagar de fato.
     */
    private function withinWindow(UserPlan $userPlan, int $windowDays): bool
    {
        $firstSubscribedAt = UserPlan::query()
            ->where('authentication_id', $userPlan->authentication_id)
            ->where('plan_id', $userPlan->plan_id)
            ->min('start_date');

        $reference = $firstSubscribedAt !== null
            ? Carbon::parse($firstSubscribedAt)
            : $userPlan->start_date;

        if ($reference === null) {
            return false;
        }

        return now()->lessThanOrEqualTo($reference->copy()->addDays($windowDays));
    }

    /**
     * Teto de consumo do ciclo.
     *
     * Plano ilimitado (`request_limit` = 0) não tem percentual a exceder, então
     * a comporta não se aplica — o que deixa a janela como única barreira nesses
     * planos, e é uma consequência a considerar antes de ligar a política num
     * catálogo que só tem planos ilimitados.
     */
    private function usageExceeded(UserPlan $userPlan): bool
    {
        // Lido pela relação, e não pelo accessor: um plano excluído deixa
        // `$userPlan->plan` nulo em tempo de execução, ainda que a análise
        // estática o tipe como sempre presente. `value()` devolve null nesse
        // caso, o cast vira 0 e a comporta de consumo simplesmente não se
        // aplica — o mesmo tratamento dado a plano ilimitado.
        $limit = (int) $userPlan->plan()->value('request_limit');

        if ($limit <= 0) {
            return false;
        }

        $maxPercent = (float) config('api-key.refund.max_usage_percent', 50);
        $usedPercent = ((int) $userPlan->requests_used / $limit) * 100;

        return $usedPercent > $maxPercent;
    }
}
