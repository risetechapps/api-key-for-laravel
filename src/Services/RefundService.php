<?php

namespace RiseTechApps\ApiKey\Services;

use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Payment\PaymentRefundClient;
use MercadoPago\MercadoPagoConfig;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

/**
 * Devolve o dinheiro de uma assinatura e encerra o acesso.
 *
 * Um único caminho para os dois estornos que o pacote faz — o automático, da
 * política de arrependimento, e o manual, do painel admin — para que os dois
 * gravem o mesmo rastro. Antes só o painel estornava, e sem marcar nada além do
 * `active = false`: não havia como distinguir uma assinatura encerrada de uma
 * devolvida, nem impedir o mesmo pagamento de ser estornado duas vezes.
 */
class RefundService
{
    /**
     * @param  float|null  $amount  Valor a devolver. Null consulta o pagamento no
     *                              gateway e devolve o saldo cheio — usado pelo
     *                              painel, onde o operador não digita valor.
     * @return string Id do estorno no gateway.
     *
     * @throws \Exception Falha do gateway; o chamador decide o que mostrar.
     */
    public function refund(UserPlan $userPlan, ?float $amount = null): string
    {
        MercadoPagoConfig::setAccessToken(config('api-key.mercadopago.access_token'));

        $paymentId = (int) $userPlan->payment_id;

        if ($amount === null) {
            $amount = (float) new PaymentClient()->get($paymentId)->transaction_amount;
        }

        $refund = new PaymentRefundClient()->refund($paymentId, $amount);

        $refundId = (string) ($refund->id ?? '');

        // Só grava depois de o gateway confirmar. Marcar antes deixaria a
        // assinatura como devolvida sem o dinheiro ter saído, e o registro é
        // justamente o que impede um segundo estorno do mesmo pagamento.
        $userPlan->update([
            'active' => false,
            'refunded_at' => now(),
            'refund_id' => $refundId,
        ]);

        return $refundId;
    }
}
