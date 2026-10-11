<?php

namespace RiseTechApps\ApiKey\Services;

use Illuminate\Support\Carbon;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\MercadoPagoConfig;
use RiseTechApps\ApiKey\Gateways\GatewayRegistry;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Traits\BuildsPaymentPayer;
use RiseTechApps\ApiKey\Traits\SendsIdempotentPayments;

/**
 * Cobranças PIX no gateway ativo.
 *
 * PIX nasce pendente: o pagamento existe, o QR é exibido no painel e a
 * confirmação chega depois pelo webhook (ou pela reconciliação). Quem chama
 * registra o PendingPayment. O QR nunca vai por e-mail.
 */
class PixService
{
    use BuildsPaymentPayer, SendsIdempotentPayments;

    public function enabled(): bool
    {
        $driver = GatewayRegistry::active();

        return $driver ? $driver->pixEnabled() : (bool) config('api-key.mercadopago.pix.enabled', false);
    }

    /** Validade do QR, no gateway ativo. Nunca menos de 30 minutos. */
    public static function expiresAt(): Carbon
    {
        $gateway = PaymentSettingsService::activeGateway();

        return now()->addMinutes(max(30, (int) config("api-key.{$gateway}.pix.expiration_minutes", 30)));
    }

    /**
     * Cria a cobrança PIX e devolve o pagamento do gateway.
     *
     * @param  list<scalar|null>  $idempotencyParts
     */
    public function createCharge(
        Authentication $user,
        Plan $plan,
        float $amount,
        string $externalReference,
        Carbon $expiresAt,
        array $idempotencyParts,
        ?string $clientKey = null,
        ?array $identification = null,
    ): object {
        $description = __('api-key::messages.plan_subscription_description', ['plan' => $plan->name]);

        // Outros gateways: mesmo formato de retorno (id, status e o QR em
        // point_of_interaction), para checkout e excedente não mudarem.
        if ($driver = GatewayRegistry::active()) {
            return $driver->createPix(
                $user,
                $amount,
                $description,
                $externalReference,
                $expiresAt,
                $this->idempotencyKey($clientKey, $idempotencyParts),
                $identification['number'] ?? null,
            );
        }

        MercadoPagoConfig::setAccessToken(config('api-key.mercadopago.access_token'));

        $payer = ['email' => strtolower((string) $user->email), ...$this->payerNames($user->name)];

        if (! empty($identification['type']) && ! empty($identification['number'])) {
            $payer['identification'] = $identification;
        } elseif ($user->cpf) {
            $payer['identification'] = ['type' => 'CPF', 'number' => preg_replace('/\D/', '', (string) $user->cpf)];
        }

        $payload = [
            'transaction_amount' => $amount,
            'description' => $description,
            'payment_method_id' => 'pix',
            'payer' => $payer,
            'external_reference' => $externalReference,
            // O Mercado Pago aceita de 30 minutos a 30 dias.
            'date_of_expiration' => $expiresAt->copy()->setTimezone('America/Sao_Paulo')->format('Y-m-d\TH:i:s.vP'),
            'additional_info' => [
                'items' => [[
                    'id' => (string) $plan->getKey(),
                    'title' => $description,
                    'category_id' => 'services',
                    'quantity' => 1,
                    'unit_price' => $amount,
                ]],
            ],
        ];

        if ($notificationUrl = $this->notificationUrl()) {
            $payload['notification_url'] = $notificationUrl;
        }

        return (new PaymentClient)->create($payload, $this->idempotentRequest($clientKey, $idempotencyParts));
    }

    /**
     * QR e links do PIX, no formato que o painel e o e-mail usam.
     *
     * @return array{qr_code: ?string, qr_code_base64: ?string, ticket_url: ?string}
     */
    public static function qrData(object $payment): array
    {
        $data = $payment->point_of_interaction->transaction_data ?? null;

        return [
            'qr_code' => $data->qr_code ?? null,
            'qr_code_base64' => $data->qr_code_base64 ?? null,
            'ticket_url' => $data->ticket_url ?? null,
        ];
    }
}
