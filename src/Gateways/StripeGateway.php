<?php

namespace RiseTechApps\ApiKey\Gateways;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RiseTechApps\ApiKey\Exceptions\PaymentMessageException;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;

/**
 * Stripe.
 *
 * Cartão: o navegador confirma um SetupIntent (`usage: off_session`) com o
 * Stripe Elements, e o pacote recebe só o id dele (`seti_…`). O cartão fica
 * no cliente do Stripe como PaymentMethod (`pm_…`) e é cobrado depois sem
 * CVV, com `off_session` na renovação e no excedente.
 *
 * Toda cobrança é um PaymentIntent e fica registrada pelo id dele (`pi_…`).
 * A referência do pacote vai em `metadata.reference`.
 *
 * PIX: PaymentIntent com `payment_method_types[]=pix`, que o Stripe só aceita
 * em conta brasileira, de R$ 0,50 a R$ 3.000,00.
 *
 * Valores em centavos; a API recebe formulário, não JSON.
 */
class StripeGateway extends AbstractCardGateway
{
    private const BASE_URL = 'https://api.stripe.com/v1';

    /** Janela aceita para o carimbo de tempo da assinatura do webhook. */
    private const SIGNATURE_TOLERANCE = 300;

    public function name(): string
    {
        return 'stripe';
    }

    public function paymentIdPrefix(): string
    {
        return 'pi_';
    }

    public function configured(): bool
    {
        return (string) $this->config('secret_key') !== '';
    }

    public function publicConfig(): array
    {
        return ['publishable_key' => $this->config('publishable_key')];
    }

    public function testConnection(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'message' => 'Secret key não configurada.'];
        }

        try {
            $response = $this->request()->get(self::BASE_URL.'/account');
        } catch (\Exception $e) {
            return ['ok' => false, 'message' => 'Sem resposta do Stripe: '.$e->getMessage()];
        }

        if ($response->status() === 401) {
            return ['ok' => false, 'message' => 'Secret key recusada pelo Stripe.'];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => "Stripe respondeu HTTP {$response->status()}."];
        }

        $mode = str_starts_with((string) $this->config('secret_key'), 'sk_live_') ? 'produção' : 'teste';
        $country = (string) $response->json('country');
        $message = "Conexão com o Stripe funcionando (conta {$response->json('id')}, modo {$mode}).";

        if ($country !== '' && $country !== 'BR' && $this->config('pix.enabled')) {
            $message .= ' Atenção: PIX só funciona em conta brasileira.';
        }

        return ['ok' => true, 'message' => $message];
    }

    /**
     * Abre o SetupIntent que o navegador confirma com o cartão. O id do
     * usuário vai no metadata, e é ele que o saveCard confere: um SetupIntent
     * de outra pessoa não salva cartão nesta conta.
     *
     * @return array{client_secret: string, id: string}
     *
     * @throws \RuntimeException
     */
    public function createSetupIntent(Authentication $user): array
    {
        $response = $this->request()
            ->withHeaders(['Idempotency-Key' => $this->idempotency()])
            ->post(self::BASE_URL.'/setup_intents', [
                'customer' => $this->customerFor($user),
                'usage' => 'off_session',
                'payment_method_types' => ['card'],
                'metadata' => ['api_key_user' => (string) $user->getKey()],
            ]);

        if (! $response->successful() || ! $response->json('client_secret')) {
            logglyError()->withContext(['status' => $response->status(), 'body' => $this->safeBody($response)])
                ->log('Stripe setup intent creation failed');

            throw new PaymentMessageException(__('api-key::messages.error_processing_payment'));
        }

        return ['client_secret' => (string) $response->json('client_secret'), 'id' => (string) $response->json('id')];
    }

    /**
     * Salva o cartão de um SetupIntent já confirmado no navegador. O Stripe
     * já autenticou e validou o cartão ao confirmar.
     */
    public function saveCard(Authentication $user, array $input): UserCard
    {
        $setupIntentId = (string) $input['card_token'];

        if (! preg_match('/^seti_[A-Za-z0-9]+$/', $setupIntentId)) {
            throw new PaymentMessageException(__('api-key::messages.invalid_payment_data'));
        }

        $response = $this->request()->get(self::BASE_URL."/setup_intents/{$setupIntentId}", ['expand' => ['payment_method']]);

        if (! $response->successful()) {
            throw new PaymentMessageException(__('api-key::messages.error_processing_payment'));
        }

        if ((string) $response->json('metadata.api_key_user') !== (string) $user->getKey()) {
            logglyWarning()->withContext(['user_id' => $user->getKey(), 'setup_intent' => $setupIntentId])
                ->log('Stripe setup intent belongs to another user');

            throw new PaymentMessageException(__('api-key::messages.invalid_payment_data'));
        }

        if ($response->json('status') !== 'succeeded' || ! $response->json('payment_method.id')) {
            throw new PaymentMessageException(__('api-key::messages.card_verification_failed'));
        }

        $card = (array) $response->json('payment_method.card', []);

        return $this->storeCard($user, [
            'last_four' => (string) ($card['last4'] ?? ''),
            'holder_name' => (string) ($response->json('payment_method.billing_details.name') ?: ($input['holder_name'] ?? null) ?: $user->name),
            'brand' => strtolower((string) ($card['brand'] ?? 'outros')),
            'expiry_month' => str_pad((string) ($card['exp_month'] ?? 1), 2, '0', STR_PAD_LEFT),
            'expiry_year' => $this->fullYear($card['exp_year'] ?? 0),
            'gateway_customer_id' => (string) $response->json('customer'),
            'gateway_card_id' => (string) $response->json('payment_method.id'),
        ]);
    }

    public function deleteCard(UserCard $card): bool
    {
        if (! $card->gateway_card_id || ! $this->configured()) {
            return true;
        }

        try {
            $response = $this->request()->post(self::BASE_URL."/payment_methods/{$card->gateway_card_id}/detach");
        } catch (\Exception $e) {
            logglyWarning()->withContext(['card_id' => $card->getKey(), 'error' => $e->getMessage()])->log('Stripe card removal failed');

            return false;
        }

        // Já desvinculado ou inexistente: para o pacote, removido.
        if (! $response->successful() && ! in_array($response->status(), [400, 404], true)) {
            logglyWarning()->withContext(['card_id' => $card->getKey(), 'status' => $response->status()])->log('Stripe card removal failed');

            return false;
        }

        return true;
    }

    /**
     * Na compra feita pelo titular, a cobrança vai `on_session`: se o banco
     * pedir autenticação, o pagamento volta com `client_action` e o painel
     * abre o 3DS. Na renovação vai `off_session`, e o pedido de autenticação
     * vira recusa (o assinante paga pelo painel).
     */
    public function chargeCard(UserCard $card, float $amount, string $reference, string $description, string $idempotencyKey, bool $customerInitiated = false, array $input = []): object
    {
        $body = [
            'amount' => $this->cents($amount),
            'currency' => 'brl',
            'customer' => $card->gateway_customer_id,
            'payment_method' => $card->gateway_card_id,
            'payment_method_types' => ['card'],
            'confirm' => 'true',
            'off_session' => $customerInitiated ? 'false' : 'true',
            'description' => mb_substr($description, 0, 1000),
            'metadata' => ['reference' => $reference],
        ];

        if ($suffix = $this->statementSuffix()) {
            $body['statement_descriptor_suffix'] = $suffix;
        }

        $response = $this->request()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post(self::BASE_URL.'/payment_intents', $body);

        $intent = $this->intentOrFail($response);
        $payment = self::toPayment($intent);

        if ($customerInitiated && ($intent['status'] ?? null) === 'requires_action' && ! empty($intent['client_secret'])) {
            $payment->client_action = ['type' => 'stripe_next_action', 'client_secret' => (string) $intent['client_secret']];
        }

        return $payment;
    }

    public function createPix(Authentication $user, float $amount, string $description, string $reference, Carbon $expiresAt, string $idempotencyKey, ?string $cpf = null): object
    {
        if ($amount < 0.5 || $amount > 3000) {
            throw new PaymentMessageException(__('api-key::messages.pix_amount_out_of_range', ['min' => 'R$ 0,50', 'max' => 'R$ 3.000,00']));
        }

        $response = $this->request()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post(self::BASE_URL.'/payment_intents', [
                'amount' => $this->cents($amount),
                'currency' => 'brl',
                'customer' => $this->customerFor($user),
                'payment_method_types' => ['pix'],
                'payment_method_data' => [
                    'type' => 'pix',
                    'billing_details' => ['name' => $user->name, 'email' => strtolower((string) $user->email)],
                ],
                'payment_method_options' => ['pix' => ['expires_after_seconds' => max(60, (int) now()->diffInSeconds($expiresAt, false))]],
                'confirm' => 'true',
                'description' => mb_substr($description, 0, 1000),
                'metadata' => ['reference' => $reference],
            ]);

        $intent = $this->intentOrFail($response);
        $qr = (array) ($intent['next_action']['pix_display_qr_code'] ?? []);

        return $this->withPix(
            self::toPayment($intent),
            $qr['data'] ?? null,
            $this->imageBase64($qr['image_url_png'] ?? null),
            $qr['hosted_instructions_url'] ?? null,
        );
    }

    public function findPayment(string $id): ?object
    {
        $response = $this->request()->get(self::BASE_URL."/payment_intents/{$id}", ['expand' => ['latest_charge']]);

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new \RuntimeException("Stripe payment intent lookup failed with HTTP {$response->status()}");
        }

        return self::toPayment((array) $response->json());
    }

    public function refund(string $id, ?float $amount = null): string
    {
        $body = ['payment_intent' => $id];

        if ($amount !== null) {
            $body['amount'] = $this->cents($amount);
        }

        $response = $this->request()
            ->withHeaders(['Idempotency-Key' => 'refund-'.$id.'-'.($amount ?? 'full')])
            ->post(self::BASE_URL.'/refunds', $body);

        if (! $response->successful() || ! $response->json('id')) {
            logglyError()->withContext(['payment_id' => $id, 'status' => $response->status(), 'body' => $this->safeBody($response)])
                ->log('Stripe refund failed');

            throw new \RuntimeException("Stripe refund failed with HTTP {$response->status()}");
        }

        return (string) $response->json('id');
    }

    /**
     * Confere a assinatura `Stripe-Signature` (HMAC do `t.corpo` com o
     * signing secret) quando o secret está configurado.
     */
    public function webhookPaymentIds(Request $request): array
    {
        $secret = (string) $this->config('webhook_secret');
        $payload = (string) $request->getContent();

        if ($secret !== '' && ! $this->signatureIsValid((string) $request->header('Stripe-Signature'), $payload, $secret)) {
            throw new AuthenticationException;
        }

        $object = (array) $request->input('data.object', []);

        $id = match ($object['object'] ?? null) {
            'payment_intent' => $object['id'] ?? null,
            'charge', 'refund' => $object['payment_intent'] ?? null,
            default => null,
        };

        return is_string($id) && preg_match('/^pi_[A-Za-z0-9]+$/', $id) ? [$id] : [];
    }

    /**
     * PaymentIntent no formato de pagamento do pacote.
     *
     * @param  array<string, mixed>  $intent
     */
    public static function toPayment(array $intent): object
    {
        $isPix = in_array('pix', (array) ($intent['payment_method_types'] ?? []), true);
        $charge = is_array($intent['latest_charge'] ?? null) ? $intent['latest_charge'] : [];
        $error = (array) ($intent['last_payment_error'] ?? []);

        $status = match ($intent['status'] ?? null) {
            'succeeded' => ($charge['refunded'] ?? false) ? 'refunded' : 'approved',
            'processing' => 'in_process',
            'requires_action', 'requires_confirmation' => $isPix ? 'pending' : 'in_process',
            'requires_payment_method', 'canceled' => 'rejected',
            default => 'in_process',
        };

        return (object) [
            'id' => (string) ($intent['id'] ?? ''),
            'status' => $status,
            'status_detail' => (string) ($error['decline_code'] ?? $error['code'] ?? $intent['cancellation_reason'] ?? ''),
            'external_reference' => $intent['metadata']['reference'] ?? null,
            'transaction_amount' => round(((int) ($intent['amount'] ?? 0)) / 100, 2),
            'payment_method_id' => $isPix ? 'pix' : strtolower((string) ($charge['payment_method_details']['card']['brand'] ?? 'credit_card')),
        ];
    }

    /**
     * Cliente do Stripe deste usuário: o do cartão salvo, o lembrado em cache
     * (SetupIntent aberto sem cartão concluído) ou um novo.
     */
    private function customerFor(Authentication $user): string
    {
        if ($existing = $this->existingCustomerId($user)) {
            return $existing;
        }

        $cacheKey = 'api-key:stripe:customer:'.sha1((string) $this->config('secret_key')).':'.$user->getKey();

        if ($cached = Cache::get($cacheKey)) {
            return (string) $cached;
        }

        $response = $this->request()
            ->withHeaders(['Idempotency-Key' => 'customer-'.$user->getKey().'-'.substr(sha1((string) $this->config('secret_key')), 0, 8)])
            ->post(self::BASE_URL.'/customers', [
                'name' => $user->name,
                'email' => strtolower((string) $user->email),
                'metadata' => ['api_key_user' => (string) $user->getKey()],
            ]);

        if (! $response->successful() || ! $response->json('id')) {
            logglyError()->withContext(['status' => $response->status(), 'body' => $this->safeBody($response)])
                ->log('Stripe customer creation failed');

            throw new PaymentMessageException(__('api-key::messages.error_processing_payment'));
        }

        Cache::forever($cacheKey, (string) $response->json('id'));

        return (string) $response->json('id');
    }

    private function statementSuffix(): ?string
    {
        $text = (string) $this->config('statement_descriptor');

        if ($text === '') {
            return null;
        }

        return mb_substr(trim(preg_replace('/[^A-Za-z0-9 ]/', '', Str::ascii($text)) ?: ''), 0, 22) ?: null;
    }

    private function signatureIsValid(string $header, string $payload, string $secret): bool
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if (! $timestamp || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::SIGNATURE_TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recusa do cartão chega como HTTP 402 com o PaymentIntent dentro do
     * erro: vira pagamento recusado, não falha do gateway.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    private function intentOrFail(Response $response): array
    {
        if ($response->successful() && $response->json('id')) {
            return (array) $response->json();
        }

        if (is_array($intent = $response->json('error.payment_intent'))) {
            $intent['last_payment_error'] ??= (array) $response->json('error');

            return $intent;
        }

        logglyError()->withContext(['status' => $response->status(), 'body' => $this->safeBody($response)])
            ->log('Stripe payment intent request failed');

        throw new \RuntimeException("Stripe payment intent request failed with HTTP {$response->status()}");
    }

    private function request(): PendingRequest
    {
        return Http::withToken((string) $this->config('secret_key'))
            ->asForm()
            ->acceptJson()
            ->timeout(30);
    }
}
