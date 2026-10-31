<?php

namespace RiseTechApps\ApiKey\Gateways;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RiseTechApps\ApiKey\Exceptions\PaymentMessageException;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;

/**
 * Pagar.me (API v5).
 *
 * Toda cobrança é um pedido (`POST /orders`) e fica registrada no pacote pelo
 * id do pedido (`or_…`): é o que o webhook `order.*` traz, e de onde saem a
 * referência (`metadata.reference`), o status e a cobrança (`ch_…`) usada no
 * estorno.
 *
 * Cobrança sem CVV: o cartão é salvo com o token gerado no navegador (com o
 * CVV) e validado com autorização de valor zero (`verify_card`). A primeira
 * cobrança vai como `recurrence_cycle: first`; as seguintes como `subsequent`,
 * com `payment_origin.charge_id` da primeira, pelo `card_id`, sem CVV.
 *
 * Valores na API do Pagar.me são em centavos.
 */
class PagarmeGateway extends AbstractCardGateway
{
    private const BASE_URL = 'https://api.pagar.me/core/v5';

    public function name(): string
    {
        return 'pagarme';
    }

    public function paymentIdPrefix(): string
    {
        return 'or_';
    }

    public function configured(): bool
    {
        return (string) $this->config('secret_key') !== '';
    }

    public function publicConfig(): array
    {
        return ['public_key' => $this->config('public_key')];
    }

    public function testConnection(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'message' => 'Secret key não configurada.'];
        }

        try {
            $response = $this->request()->get(self::BASE_URL.'/customers', ['size' => 1]);
        } catch (\Exception $e) {
            return ['ok' => false, 'message' => 'Sem resposta do Pagar.me: '.$e->getMessage()];
        }

        return match (true) {
            $response->successful() => ['ok' => true, 'message' => 'Conexão com o Pagar.me funcionando.'],
            $response->status() === 401 => ['ok' => false, 'message' => 'Secret key recusada pelo Pagar.me.'],
            default => ['ok' => false, 'message' => "Pagar.me respondeu HTTP {$response->status()}."],
        };
    }

    /**
     * Salva o cartão no cliente do Pagar.me, validado com autorização de valor
     * zero. O CVV vai no token e não é guardado em lugar nenhum.
     */
    public function saveCard(Authentication $user, array $input): UserCard
    {
        $customerId = $this->customerFor($user, $input['cpf'] ?? null);

        $body = ['token' => $input['card_token'], 'options' => ['verify_card' => true]];

        if ($billing = $this->billingAddress($user)) {
            $body['billing_address'] = $billing;
        }

        $response = $this->request()->post(self::BASE_URL."/customers/{$customerId}/cards", $body);

        if (! $response->successful() || ! $response->json('id')) {
            logglyWarning()->withContext(['status' => $response->status(), 'body' => $this->safeBody($response)])
                ->log('Pagar.me card creation refused');

            throw new PaymentMessageException($response->status() === 412
                ? __('api-key::messages.card_verification_failed')
                : __('api-key::messages.error_processing_payment'));
        }

        return $this->storeCard($user, [
            'last_four' => (string) $response->json('last_four_digits'),
            'holder_name' => (string) ($response->json('holder_name') ?: ($input['holder_name'] ?? null) ?: $user->name),
            'brand' => strtolower((string) $response->json('brand', 'outros')),
            'expiry_month' => str_pad((string) $response->json('exp_month', 1), 2, '0', STR_PAD_LEFT),
            'expiry_year' => $this->fullYear($response->json('exp_year')),
            'gateway_customer_id' => $customerId,
            'gateway_card_id' => (string) $response->json('id'),
        ]);
    }

    public function deleteCard(UserCard $card): bool
    {
        if (! $card->gateway_customer_id || ! $card->gateway_card_id || ! $this->configured()) {
            return true;
        }

        try {
            $response = $this->request()->delete(self::BASE_URL."/customers/{$card->gateway_customer_id}/cards/{$card->gateway_card_id}");
        } catch (\Exception $e) {
            logglyWarning()->withContext(['card_id' => $card->getKey(), 'error' => $e->getMessage()])->log('Pagar.me card removal failed');

            return false;
        }

        if (! $response->successful() && $response->status() !== 404) {
            logglyWarning()->withContext(['card_id' => $card->getKey(), 'status' => $response->status()])->log('Pagar.me card removal failed');

            return false;
        }

        return true;
    }

    public function chargeCard(UserCard $card, float $amount, string $reference, string $description, string $idempotencyKey, bool $customerInitiated = false, array $input = []): object
    {
        // Compra feita pelo titular (checkout) abre uma recorrência nova: troca
        // de plano ou de valor recomeça a cadeia, segundo a regra das bandeiras.
        // A renovação continua a cadeia da última compra.
        $subsequent = ! $customerInitiated && $card->gateway_first_charge_id;

        $creditCard = [
            'card_id' => $card->gateway_card_id,
            'installments' => 1,
            'statement_descriptor' => $this->statementDescriptor(),
            'recurrence_cycle' => $subsequent ? 'subsequent' : 'first',
        ];

        if ($subsequent) {
            $creditCard['payment_origin'] = ['charge_id' => $card->gateway_first_charge_id];
        }

        $response = $this->request()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post(self::BASE_URL.'/orders', [
                'code' => $this->orderCode($reference),
                'customer_id' => $card->gateway_customer_id,
                'items' => [$this->item($amount, $description, $reference)],
                'payments' => [['payment_method' => 'credit_card', 'credit_card' => $creditCard]],
                'metadata' => ['reference' => $reference],
                'closed' => true,
            ]);

        $order = $this->orderOrFail($response);
        $payment = self::toPayment($order);

        // A primeira cobrança aprovada vira a origem da recorrência.
        if ($payment->status === 'approved' && ! $subsequent && ($chargeId = $order['charges'][0]['id'] ?? null)) {
            $card->update(['gateway_first_charge_id' => (string) $chargeId]);
        }

        return $payment;
    }

    /** O cliente vai inline, com CPF e telefone, que o Pagar.me exige para PIX. */
    public function createPix(Authentication $user, float $amount, string $description, string $reference, Carbon $expiresAt, string $idempotencyKey, ?string $cpf = null): object
    {
        $document = $this->document($user, $cpf);
        $phone = $this->pagarmePhone($user);

        if (! $document || ! $phone) {
            throw new PaymentMessageException(__('api-key::messages.pix_requires_document_and_phone'));
        }

        $response = $this->request()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post(self::BASE_URL.'/orders', [
                'code' => $this->orderCode($reference),
                'customer' => [
                    'name' => $user->name,
                    'email' => strtolower((string) $user->email),
                    'type' => 'individual',
                    'document' => $document,
                    'document_type' => 'CPF',
                    'phones' => ['mobile_phone' => $phone],
                    ...(($address = $this->billingAddress($user)) ? ['address' => $address] : []),
                ],
                'items' => [$this->item($amount, $description, $reference)],
                'payments' => [['payment_method' => 'pix', 'pix' => ['expires_in' => max(60, now()->diffInSeconds($expiresAt, false))]]],
                'metadata' => ['reference' => $reference],
                'closed' => true,
            ]);

        $order = $this->orderOrFail($response);
        $transaction = $order['charges'][0]['last_transaction'] ?? [];

        return $this->withPix(
            self::toPayment($order),
            $transaction['qr_code'] ?? null,
            $this->imageBase64($transaction['qr_code_url'] ?? null),
            $transaction['qr_code_url'] ?? null,
        );
    }

    public function findPayment(string $id): ?object
    {
        $response = $this->request()->get(self::BASE_URL."/orders/{$id}");

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new \RuntimeException("Pagar.me order lookup failed with HTTP {$response->status()}");
        }

        return self::toPayment((array) $response->json());
    }

    /** Estorna o pedido (cancela a cobrança dele), inteiro ou em parte. */
    public function refund(string $id, ?float $amount = null): string
    {
        $order = $this->request()->get(self::BASE_URL."/orders/{$id}");
        $chargeId = $order->json('charges.0.id');

        if (! $order->successful() || ! $chargeId) {
            throw new \RuntimeException("Pagar.me order {$id} not found for refund");
        }

        $body = $amount !== null ? ['amount' => $this->cents($amount)] : [];

        $response = $this->request()->delete(self::BASE_URL."/charges/{$chargeId}", $body);

        if (! $response->successful()) {
            logglyError()->withContext(['order_id' => $id, 'status' => $response->status(), 'body' => $this->safeBody($response)])
                ->log('Pagar.me refund failed');

            throw new \RuntimeException("Pagar.me refund failed with HTTP {$response->status()}");
        }

        return (string) ($response->json('last_transaction.id') ?? $chargeId);
    }

    /**
     * O Pagar.me não assina a notificação. Com usuário e senha configurados, a
     * URL exige Basic Auth; de todo jeito o corpo só diz qual pedido mudou.
     */
    public function webhookPaymentIds(Request $request): array
    {
        $user = (string) $this->config('webhook_user');
        $password = (string) $this->config('webhook_password');

        if ($user !== '' || $password !== '') {
            if (! hash_equals($user, (string) $request->getUser()) || ! hash_equals($password, (string) $request->getPassword())) {
                throw new AuthenticationException;
            }
        }

        $type = (string) $request->input('type', '');

        $orderId = match (true) {
            str_starts_with($type, 'order.') => $request->input('data.id'),
            str_starts_with($type, 'charge.') => $request->input('data.order.id'),
            default => null,
        };

        return is_string($orderId) && preg_match('/^or_[A-Za-z0-9]+$/', $orderId) ? [$orderId] : [];
    }

    /**
     * Só com usuário e senha do webhook configurados: sem eles, qualquer
     * um manda a notificação, e a prova vem da consulta do pedido.
     */
    public function webhooksAuthenticated(): bool
    {
        return (string) $this->config('webhook_user') !== '' || (string) $this->config('webhook_password') !== '';
    }

    /**
     * Pedido do Pagar.me no formato de pagamento do pacote.
     *
     * @param  array<string, mixed>  $order
     */
    public static function toPayment(array $order): object
    {
        $charge = $order['charges'][0] ?? [];
        $chargeStatus = strtolower((string) ($charge['status'] ?? ''));

        $status = match (strtolower((string) ($order['status'] ?? ''))) {
            'paid' => 'approved',
            'failed' => 'rejected',
            'canceled' => ($charge['paid_amount'] ?? 0) > 0 || $chargeStatus === 'refunded' ? 'refunded' : 'rejected',
            default => match ($chargeStatus) {
                'paid' => 'approved',
                'failed' => 'rejected',
                default => ($charge['payment_method'] ?? null) === 'pix' ? 'pending' : 'in_process',
            },
        };

        $transaction = $charge['last_transaction'] ?? [];

        return (object) [
            'id' => (string) ($order['id'] ?? ''),
            'status' => $status,
            'status_detail' => (string) ($transaction['acquirer_message'] ?? $transaction['status'] ?? ''),
            'external_reference' => $order['metadata']['reference'] ?? null,
            'transaction_amount' => round(((int) ($order['amount'] ?? $charge['amount'] ?? 0)) / 100, 2),
            'payment_method_id' => ($charge['payment_method'] ?? null) === 'pix' ? 'pix' : strtolower((string) ($transaction['card']['brand'] ?? 'credit_card')),
        ];
    }

    private function customerFor(Authentication $user, ?string $cpf): string
    {
        if ($existing = $this->existingCustomerId($user)) {
            return $existing;
        }

        $customer = [
            'name' => $user->name,
            'email' => strtolower((string) $user->email),
            // O Pagar.me identifica o cliente pelo `code`: o mesmo usuário não
            // vira dois clientes se a linha local se perder.
            'code' => (string) $user->getKey(),
            'type' => 'individual',
        ];

        if ($document = $this->document($user, $cpf)) {
            $customer['document'] = $document;
            $customer['document_type'] = 'CPF';
        }

        if ($phone = $this->pagarmePhone($user)) {
            $customer['phones'] = ['mobile_phone' => $phone];
        }

        // Conta PSP do Pagar.me exige endereço e telefone do cliente.
        if ($address = $this->billingAddress($user)) {
            $customer['address'] = $address;
        }

        $response = $this->request()->post(self::BASE_URL.'/customers', $customer);

        if (! $response->successful() || ! $response->json('id')) {
            logglyError()->withContext(['status' => $response->status(), 'body' => $this->safeBody($response)])
                ->log('Pagar.me customer creation failed');

            throw new PaymentMessageException(__('api-key::messages.error_processing_payment'));
        }

        return (string) $response->json('id');
    }

    /** @return array<string, mixed> */
    private function item(float $amount, string $description, string $reference): array
    {
        return [
            'amount' => $this->cents($amount),
            'description' => mb_substr($description, 0, 256),
            'quantity' => 1,
            'code' => mb_substr(explode('|', $reference)[0] ?: 'plan', 0, 52),
        ];
    }

    /**
     * `code` do pedido: a referência quando cabe, senão um resumo dela. A
     * referência completa vai sempre em `metadata.reference`.
     */
    private function orderCode(string $reference): string
    {
        return strlen($reference) <= 52 ? $reference : substr(hash('sha256', $reference), 0, 32).'-'.Str::lower(Str::random(6));
    }

    private function statementDescriptor(): string
    {
        $text = (string) ($this->config('statement_descriptor') ?: config('app.name') ?: 'Assinatura');

        return mb_substr(preg_replace('/[^A-Za-z0-9 ]/', '', Str::ascii($text)) ?: 'Assinatura', 0, 13);
    }

    /** @return array{country_code: string, area_code: string, number: string}|null */
    private function pagarmePhone(Authentication $user): ?array
    {
        $phone = $this->phone($user);

        return $phone ? ['country_code' => '55', 'area_code' => $phone['area_code'], 'number' => $phone['number']] : null;
    }

    /** @return array<string, string>|null */
    private function billingAddress(Authentication $user): ?array
    {
        $address = $this->address($user);

        return $address ? [
            'line_1' => trim($address['number'].', '.$address['street'].', '.$address['district'], ' ,'),
            'line_2' => $address['complement'],
            'zip_code' => $address['zip'],
            'city' => $address['city'],
            'state' => $address['state'],
            'country' => 'BR',
        ] : null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    private function orderOrFail(Response $response): array
    {
        // Recusa do cartão também vem como pedido (status `failed`).
        if ($response->json('id') && $response->json('status')) {
            return (array) $response->json();
        }

        logglyError()->withContext(['status' => $response->status(), 'body' => $this->safeBody($response)])
            ->log('Pagar.me order request failed');

        throw new \RuntimeException("Pagar.me order request failed with HTTP {$response->status()}");
    }

    /**
     * O Pagar.me ecoa o pedido inteiro, com documento e telefone, e isso iria
     * parar na tela de logs.
     */
    protected function safeBody(Response $response): ?array
    {
        $body = parent::safeBody($response);

        unset($body['charges']);

        return $body;
    }

    private function request(): PendingRequest
    {
        return Http::withBasicAuth((string) $this->config('secret_key'), '')
            ->acceptJson()
            ->timeout(30);
    }
}
