<?php

namespace RiseTechApps\ApiKey\Traits;

use MercadoPago\Client\Common\RequestOptions;

/**
 * Idempotency for charges sent to Mercado Pago.
 *
 * A card charge is not safe to repeat. The dangerous case is not the user being
 * careless — it is the network: the charge is accepted upstream, the response is
 * lost to a timeout, and the caller has no way to tell "never happened" from
 * "happened, answer lost". A buyer staring at a spinner then presses the button
 * again and pays twice.
 *
 * `X-Idempotency-Key` moves that decision to the gateway: a repeated key returns
 * the original payment instead of creating a second one. The renewal path already
 * had equivalent protection (ProcessPlanRenewalJob is ShouldBeUnique with
 * tries = 1); the interactive checkout — where an anxious human is doing the
 * retrying — had none.
 */
trait SendsIdempotentPayments
{
    /**
     * Build the request options carrying the idempotency key.
     *
     * @param  string|null  $clientKey  Key supplied by the caller, when there is one.
     * @param  array  $parts  Values identifying this charge, used to derive
     *                        a key when the caller supplied none.
     */
    protected function idempotentRequest(?string $clientKey, array $parts, ?string $deviceId = null): RequestOptions
    {
        $headers = ['X-Idempotency-Key: '.$this->idempotencyKey($clientKey, $parts)];

        // Identificacao do dispositivo, coletada no navegador pelo security.js
        // do Mercado Pago. É sinal de antifraude: sem ela a analise de risco
        // trabalha com menos informacao e recusas cc_rejected_high_risk ficam
        // mais frequentes em cartao legitimo. Enviada apenas quando o cliente a
        // coletou — um valor vazio no header nao ajuda e polui a requisicao.
        if ($deviceId !== null && trim($deviceId) !== '') {
            $headers[] = 'X-meli-session-id: '.trim($deviceId);
        }

        $options = new RequestOptions;
        $options->setCustomHeaders($headers);

        return $options;
    }

    /**
     * URL de notificacao a enviar no pagamento, ou null quando nao configurada.
     *
     * A revisao de qualidade do Mercado Pago exige `notification_url` no corpo
     * da requisicao; cadastrar a URL apenas no painel nao satisfaz a checagem.
     * Em contrapartida o gateway valida o endereco e recusa o pagamento se ele
     * nao for HTTPS publico, entao em desenvolvimento a config fica nula e o
     * campo simplesmente nao vai.
     */
    protected function notificationUrl(): ?string
    {
        $url = config('api-key.mercadopago.notification_url');

        return is_string($url) && trim($url) !== '' ? trim($url) : null;
    }

    /**
     * A client-supplied key is authoritative: only the client knows that two
     * submissions are the same attempt rather than a deliberate second purchase.
     *
     * The derived fallback keeps the protection meaningful for callers that send
     * nothing — notably the package's own SPA. It is a digest of who is paying,
     * for what, how much, and with which card token. The token carries most of
     * the weight: Mercado Pago tokens are single-use, so a retry that reuses the
     * same token is the same attempt, while a genuinely new purchase always
     * arrives with a fresh one. That does mean a re-tokenized retry is treated as
     * a new charge, which is exactly why the client should send its own key.
     */
    protected function idempotencyKey(?string $clientKey, array $parts): string
    {
        $clientKey = trim((string) $clientKey);

        if ($clientKey !== '') {
            return $clientKey;
        }

        return hash('sha256', implode('|', array_map(
            static fn ($part) => is_float($part) ? number_format($part, 2, '.', '') : (string) $part,
            $parts
        )));
    }
}
