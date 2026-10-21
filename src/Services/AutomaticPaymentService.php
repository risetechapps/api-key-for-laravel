<?php

namespace RiseTechApps\ApiKey\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;

/**
 * Pagamentos Automáticos do Mercado Pago: cobrança do cartão salvo sem CVV.
 *
 * A cobrança comum (`/v1/payments`) exige um token novo a cada pagamento, e a
 * conta pode exigir o CVV para gerá-lo, o que é impossível numa renovação feita
 * sem o titular presente. Os Pagamentos Automáticos resolvem isso em duas
 * etapas:
 *
 *   1. Perfil de pagamento: criado uma vez, com um token gerado com o CVV
 *      (`POST /v1/customers/{id}/payment-profiles`). O gateway valida o cartão
 *      sozinho, com uma autorização de valor zero.
 *   2. Cobrança: `POST /v1/orders` com o perfil e `stored_credential` iniciado
 *      pelo vendedor, sem token e sem CVV.
 *
 * A solução depende de autorização da equipe Comercial do Mercado Pago. Sem ela
 * o gateway responde 403, e `charge()` devolve null para o chamador seguir pelo
 * caminho antigo. Por isso o pacote pode deixar isto ligado em qualquer conta.
 *
 * Toda cobrança feita aqui é identificada pelo id da order (`ORD…`), e não pelo
 * id de pagamento numérico da API antiga: é o id que o webhook `order` traz, que
 * a consulta e o estorno aceitam, e que vai para `user_plans.payment_id`.
 */
class AutomaticPaymentService
{
    private const BASE_URL = 'https://api.mercadopago.com';

    public static function enabled(): bool
    {
        return (bool) config('api-key.mercadopago.automatic_payments.enabled', true);
    }

    /** Se o id é de uma order (Orders API), e não de um pagamento da API antiga. */
    public static function isOrderId(?string $id): bool
    {
        // Ids da Orders API são `ORD` seguido de letras e números. O PagBank
        // também começa com ORD (`ORDE_…`), e o `_` é o que separa os dois.
        return is_string($id) && preg_match('/^ORD[0-9A-Z]+$/i', $id) === 1;
    }

    /**
     * Cria o perfil de pagamento do cartão. Melhor esforço: o cartão já está
     * salvo, e sem perfil ele continua servindo para pagar com CVV no painel.
     *
     * @param  string|null  $token  Token gerado com o CVV e ainda não usado.
     * @return bool Se o cartão terminou com perfil pronto para cobrar.
     */
    public function registerProfile(UserCard $card, ?string $token, ?string $paymentTypeId = null): bool
    {
        // Perfil já pronto vale antes de tudo: sem isto, a compra com um cartão
        // que já renova sozinho (sem token novo) oferecia ativar de novo.
        if ($card->hasReadyPaymentProfile()) {
            return true;
        }

        if (! self::enabled() || ! $token || ! $card->mp_customer_id) {
            return false;
        }

        try {
            $response = $this->request()
                ->withHeaders(['X-Idempotency-Key' => (string) Str::uuid()])
                ->post(self::BASE_URL."/v1/customers/{$card->mp_customer_id}/payment-profiles", [
                    'description' => mb_substr((string) config('app.name') ?: 'Assinatura', 0, 60),
                    'statement_descriptor' => mb_substr((string) config('app.name') ?: 'Assinatura', 0, 22),
                    'payment_methods' => [[
                        'id' => $card->brand,
                        'type' => in_array($paymentTypeId, ['credit_card', 'debit_card', 'prepaid_card'], true)
                            ? $paymentTypeId
                            : 'credit_card',
                        'token' => $token,
                        'default_method' => true,
                    ]],
                ]);
        } catch (\Exception $e) {
            logglyWarning()->withContext(['card_id' => $card->getKey(), 'error' => $e->getMessage()])
                ->log('MP payment profile creation failed');

            return false;
        }

        if ($response->status() === 403) {
            logglyInfo()->withContext(['card_id' => $card->getKey(), 'body' => $response->json()])
                ->log('MP payment profile not allowed: automatic payments not enabled for this account');

            return false;
        }

        if (! $response->successful() || ! $response->json('id')) {
            logglyWarning()->withContext(['card_id' => $card->getKey(), 'status' => $response->status(), 'body' => $response->json()])
                ->log('MP payment profile creation refused');

            return false;
        }

        $card->update([
            'mp_payment_profile_id' => (string) $response->json('id'),
            'mp_payment_profile_status' => strtoupper((string) $response->json('status', 'PENDING')),
        ]);

        logglyInfo()->withContext([
            'card_id' => $card->getKey(),
            'profile_status' => $card->mp_payment_profile_status,
        ])->log('MP payment profile created');

        return $card->hasReadyPaymentProfile();
    }

    /**
     * Apaga o perfil do cartão no gateway. 404 conta como apagado.
     */
    public function deleteProfile(UserCard $card): bool
    {
        if (! $card->mp_payment_profile_id || ! $card->mp_customer_id) {
            return true;
        }

        try {
            $response = $this->request()
                ->delete(self::BASE_URL."/v1/customers/{$card->mp_customer_id}/payment-profiles/{$card->mp_payment_profile_id}");
        } catch (\Exception $e) {
            logglyWarning()->withContext(['card_id' => $card->getKey(), 'error' => $e->getMessage()])
                ->log('MP payment profile removal failed');

            return false;
        }

        if (! $response->successful() && $response->status() !== 404) {
            logglyWarning()->withContext(['card_id' => $card->getKey(), 'status' => $response->status(), 'body' => $response->json()])
                ->log('MP payment profile removal failed');

            return false;
        }

        return true;
    }

    /**
     * Atualiza o status do perfil (notificação `payment_profile` do gateway).
     */
    public function refreshProfile(string $profileId): bool
    {
        $card = UserCard::where('mp_payment_profile_id', $profileId)->first();

        if (! $card || ! $card->mp_customer_id) {
            return false;
        }

        $response = $this->request()
            ->get(self::BASE_URL."/v1/customers/{$card->mp_customer_id}/payment-profiles/{$profileId}");

        if (! $response->successful()) {
            logglyWarning()->withContext(['card_id' => $card->getKey(), 'status' => $response->status()])
                ->log('MP payment profile lookup failed');

            return false;
        }

        $card->update(['mp_payment_profile_status' => strtoupper((string) $response->json('status', ''))]);

        return true;
    }

    /**
     * Cobra o cartão pelo perfil, sem CVV.
     *
     * @param  string  $reference  `external_reference`. A Orders API aceita até
     *                             64 caracteres, só letras, números, `-` e `_`.
     * @return object|null O desfecho no formato de pagamento que o resto do
     *                     pacote entende (ver toPayment()), ou null quando a
     *                     conta não tem Pagamentos Automáticos e o chamador deve
     *                     seguir pelo caminho antigo.
     *
     * @throws \RuntimeException Falha de rede ou do gateway sem resposta
     *                           utilizável: não dá para saber se a cobrança
     *                           aconteceu, então o chamador não pode tratar
     *                           como recusa.
     */
    public function charge(UserCard $card, float $amount, string $reference, string $idempotencyKey): ?object
    {
        if (! self::enabled() || ! $card->hasReadyPaymentProfile() || ! $card->mp_customer_id) {
            return null;
        }

        $value = number_format($amount, 2, '.', '');

        $response = $this->request()
            ->withHeaders(['X-Idempotency-Key' => $idempotencyKey])
            ->post(self::BASE_URL.'/v1/orders', [
                'type' => 'online',
                'processing_mode' => 'automatic',
                'external_reference' => $reference,
                'total_amount' => $value,
                'payer' => ['customer_id' => $card->mp_customer_id],
                'transactions' => [
                    'payments' => [[
                        'amount' => $value,
                        'automatic_payments' => ['payment_profile_id' => $card->mp_payment_profile_id],
                        'stored_credential' => [
                            'payment_initiator' => 'merchant',
                            'reason' => 'recurring',
                            'first_payment' => false,
                        ],
                    ]],
                ],
            ]);

        // Recusa do cartão também vem como order, às vezes com status HTTP de
        // erro: o que decide é o corpo.
        if ($response->json('id') && $response->json('status')) {
            return self::toPayment((array) $response->json());
        }

        if ($response->status() === 403) {
            logglyInfo()->withContext(['card_id' => $card->getKey(), 'body' => $response->json()])
                ->log('MP automatic payment not allowed: account not authorized, falling back');

            return null;
        }

        if ($response->clientError()) {
            // Perfil removido, cartão vencido no cofre, dado recusado: o cartão
            // não serve, e é o assinante quem resolve. Vale como recusa.
            logglyWarning()->withContext(['card_id' => $card->getKey(), 'status' => $response->status(), 'body' => $response->json()])
                ->log('MP automatic payment refused');

            return (object) [
                'id' => null,
                'status' => 'rejected',
                'status_detail' => (string) ($response->json('errors.0.code') ?? ''),
                'external_reference' => $reference,
                'transaction_amount' => $amount,
                'payment_method_id' => $card->brand,
            ];
        }

        throw new \RuntimeException("Mercado Pago order request failed with HTTP {$response->status()}");
    }

    /**
     * Consulta uma order, no formato de pagamento do pacote. Null quando a
     * order não existe nesta conta.
     *
     * @throws \RuntimeException Falha do gateway que vale tentar de novo.
     */
    public function findOrder(string $orderId): ?object
    {
        $response = $this->request()->get(self::BASE_URL."/v1/orders/{$orderId}");

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new \RuntimeException("Mercado Pago order lookup failed with HTTP {$response->status()}");
        }

        return self::toPayment((array) $response->json());
    }

    /**
     * Estorna uma order, inteira ou em parte.
     *
     * @return string Id do estorno, ou o da order quando o gateway não informa.
     *
     * @throws \RuntimeException Estorno recusado.
     */
    public function refund(string $orderId, ?float $amount = null): string
    {
        $body = [];

        if ($amount !== null) {
            $order = $this->request()->get(self::BASE_URL."/v1/orders/{$orderId}");
            $total = (float) $order->json('total_amount');
            $paymentId = $order->json('transactions.payments.0.id');

            // Estorno parcial precisa dizer de qual transação e quanto. O total
            // vai sem corpo, que é o formato do estorno integral.
            if ($paymentId && round($amount, 2) < round($total, 2)) {
                $body = ['transactions' => [['id' => $paymentId, 'amount' => number_format($amount, 2, '.', '')]]];
            }
        }

        $response = $this->request()
            ->withHeaders(['X-Idempotency-Key' => (string) Str::uuid()])
            ->post(self::BASE_URL."/v1/orders/{$orderId}/refund", $body ?: (object) []);

        if (! $response->successful()) {
            logglyError()->withContext(['order_id' => $orderId, 'status' => $response->status(), 'body' => $response->json()])
                ->log('MP order refund failed');

            throw new \RuntimeException("Mercado Pago order refund failed with HTTP {$response->status()}");
        }

        return (string) ($response->json('transactions.refunds.0.id') ?? $orderId);
    }

    /**
     * Converte uma order no formato de pagamento que o pacote já trata
     * (`id`, `status`, `status_detail`, `external_reference`,
     * `transaction_amount`, `payment_method_id`), para o job de renovação, o
     * excedente, o webhook e a reconciliação decidirem do mesmo jeito que com
     * um pagamento comum.
     *
     * @param  array<string, mixed>  $order
     */
    public static function toPayment(array $order): object
    {
        $payment = $order['transactions']['payments'][0] ?? [];

        $status = match (strtolower((string) ($order['status'] ?? ''))) {
            'processed' => 'approved',
            'failed', 'canceled', 'cancelled', 'expired' => 'rejected',
            'refunded' => 'refunded',
            // created, processing, action_required: o gateway ainda decide.
            default => 'in_process',
        };

        return (object) [
            'id' => (string) $order['id'],
            'status' => $status,
            'status_detail' => self::statusDetail((string) ($payment['status_detail'] ?? $order['status_detail'] ?? '')),
            'external_reference' => $order['external_reference'] ?? null,
            'transaction_amount' => (float) ($order['total_amount'] ?? $payment['amount'] ?? 0),
            'payment_method_id' => $payment['payment_method']['id'] ?? null,
        ];
    }

    /**
     * A Orders API informa a recusa sem o prefixo `cc_rejected_` da API antiga
     * (`insufficient_amount` em vez de `cc_rejected_insufficient_amount`). As
     * mensagens do pacote são chaveadas pelo formato antigo, então o prefixo
     * volta quando existe tradução para ele.
     */
    private static function statusDetail(string $detail): string
    {
        if ($detail === '' || str_starts_with($detail, 'cc_rejected_')) {
            return $detail;
        }

        $legacy = "cc_rejected_{$detail}";

        return __("api-key::messages.payment_rejected.{$legacy}") !== "api-key::messages.payment_rejected.{$legacy}"
            ? $legacy
            : $detail;
    }

    private function request(): PendingRequest
    {
        return Http::withToken((string) config('api-key.mercadopago.access_token'))
            ->acceptJson()
            ->timeout(30);
    }
}
