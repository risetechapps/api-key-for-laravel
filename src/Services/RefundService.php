<?php

namespace RiseTechApps\ApiKey\Services;

use Illuminate\Support\Facades\DB;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Payment\PaymentRefundClient;
use MercadoPago\MercadoPagoConfig;
use RiseTechApps\ApiKey\Exceptions\SubscriptionAlreadyRefundedException;
use RiseTechApps\ApiKey\Gateways\GatewayRegistry;
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
     * @throws SubscriptionAlreadyRefundedException O pagamento já foi devolvido;
     *                                              nada foi enviado ao gateway.
     * @throws \Exception Falha do gateway; o chamador decide o que mostrar.
     */
    public function refund(UserPlan $userPlan, ?float $amount = null): string
    {
        // A linha fica travada da checagem até a gravação do estorno. Sem isso,
        // dois pedidos simultâneos (clique duplo no painel, duas abas, o painel
        // junto do cancelamento) liam `refunded_at` vazio ao mesmo tempo e os
        // dois chegavam ao gateway.
        return DB::transaction(function () use ($userPlan, $amount) {
            $locked = UserPlan::whereKey($userPlan->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->refunded_at !== null) {
                throw new SubscriptionAlreadyRefundedException(
                    "user_plan {$locked->getKey()} already refunded ({$locked->refund_id})"
                );
            }

            // Cobrança dos Pagamentos Automáticos: é uma order, e se estorna
            // pela Orders API. O id de pagamento numérico da API antiga não
            // existe para ela.
            if ($driver = GatewayRegistry::forPaymentId($locked->payment_id)) {
                $refundId = $driver->refund((string) $locked->payment_id, $amount);
            } elseif (AutomaticPaymentService::isOrderId($locked->payment_id)) {
                $refundId = app(AutomaticPaymentService::class)->refund((string) $locked->payment_id, $amount);
            } else {
                MercadoPagoConfig::setAccessToken(config('api-key.mercadopago.access_token'));

                $paymentId = (int) $locked->payment_id;

                if ($amount === null) {
                    $amount = (float) new PaymentClient()->get($paymentId)->transaction_amount;
                }

                $refund = new PaymentRefundClient()->refund($paymentId, $amount);

                $refundId = (string) ($refund->id ?? '');
            }

            // Só grava depois de o gateway confirmar. Marcar antes deixaria a
            // assinatura como devolvida sem o dinheiro ter saído, e o registro é
            // justamente o que impede um segundo estorno do mesmo pagamento.
            $locked->update([
                'active' => false,
                'refunded_at' => now(),
                'refund_id' => $refundId,
            ]);

            $userPlan->setRawAttributes($locked->getAttributes(), true);

            return $refundId;
        });
    }
}
