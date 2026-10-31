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
 * PagBank (API de Pedidos).
 *
 * Cartão: o navegador criptografa o cartão com a chave pública da conta
 * (`PagSeguro.encryptCard`) e o pacote troca o resultado por um cartão salvo
 * (`CARD_…`), cobrado depois pelo id, sem CVV. A primeira cobrança feita pelo
 * titular vai como `recurring: INITIAL`, com o CVV que ele digitou (só passa
 * pelo servidor, nunca é gravado nem registrado); as renovações seguintes vão
 * como `SUBSEQUENT`.
 *
 * Toda cobrança é um pedido e fica registrada pelo id dele (`ORDE_…`), que é
 * o que a notificação traz. O `reference_id` aceita até 64 caracteres, então
 * a referência longa do pacote vira a forma curta (`rn-`, `ov-`) ou é achada
 * pelo registro de espera.
 *
 * Valores em centavos.
 */
class PagbankGateway extends AbstractCardGateway
{
    public function name(): string
    {
        return 'pagbank';
    }

    public function paymentIdPrefix(): string
    {
        return 'ORDE_';
    }

    public function configured(): bool
    {
        return (string) $this->config('token') !== '';
    }

    /**
     * A chave pública é criada pelo pacote na primeira vez, se não vier da
     * config, e lembrada em cache por conta.
     */
    public function publicConfig(): array
    {
        return ['public_key' => $this->publicKey()];
    }

    public function testConnection(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'message' => 'Token não configurado.'];
        }

        try {
            $response = $this->request()->get($this->baseUrl().'/public-keys/card');
        } catch (\Exception $e) {
            return ['ok' => false, 'message' => 'Sem resposta do PagBank: '.$e->getMessage()];
        }

        return match (true) {
            $response->successful(), $response->status() === 404 => ['ok' => true, 'message' => 'Conexão com o PagBank funcionando ('.($this->sandbox() ? 'sandbox' : 'produção').').'],
            in_array($response->status(), [401, 403], true) => ['ok' => false, 'message' => 'Token recusado pelo PagBank. Confira se o token é do ambiente '.($this->sandbox() ? 'sandbox' : 'de produção').'.'],
            default => ['ok' => false, 'message' => "PagBank respondeu HTTP {$response->status()}."],
        };
    }

    /**
     * Troca o cartão criptografado por um cartão salvo no PagBank. O CPF vai
     * como documento do titular, que o PagBank exige.
     */
    public function saveCard(Authentication $user, array $input): UserCard
    {
        $document = $this->document($user, $input['cpf'] ?? null);

        if (! $document) {
            throw new PaymentMessageException(__('api-key::messages.invalid_payment_data'));
        }

        $holder = (string) (($input['holder_name'] ?? null) ?: $user->name);

        $response = $this->request()->post($this->baseUrl().'/tokens/cards', [
            'encrypted' => (string) $input['card_token'],
            'holder' => ['name' => mb_substr($holder, 0, 30), 'tax_id' => $document],
        ]);

        if (! $response->successful() || ! $response->json('id')) {
            logglyWarning()->withContext(['status' => $response->status(), 'body' => $this->safeBody($response)])
                ->log('PagBank card tokenization refused');

            throw new PaymentMessageException(in_array($response->status(), [400, 422], true)
                ? __('api-key::messages.card_verification_failed')
                : __('api-key::messages.error_processing_payment'));
        }

        return $this->storeCard($user, [
            'last_four' => (string) $response->json('last_digits'),
            'holder_name' => (string) ($response->json('holder.name') ?: $holder),
            'brand' => strtolower((string) $response->json('brand', 'outros')),
            'expiry_month' => str_pad((string) $response->json('exp_month', 1), 2, '0', STR_PAD_LEFT),
            'expiry_year' => $this->fullYear($response->json('exp_year')),
            // O PagBank não tem cliente: o CPF do titular fica aqui para as
            // próximas cobranças, que pedem o documento do comprador.
            'gateway_customer_id' => $document,
            'gateway_card_id' => (string) $response->json('id'),
        ]);
    }

    /** O PagBank não expõe remoção do cartão salvo; basta o pacote esquecê-lo. */
    public function deleteCard(UserCard $card): bool
    {
        return true;
    }

    public function chargeCard(UserCard $card, float $amount, string $reference, string $description, string $idempotencyKey, bool $customerInitiated = false, array $input = []): object
    {
        $user = $card->authentication;

        if (! $user) {
            throw new \RuntimeException('PagBank card has no owner');
        }

        $securityCode = preg_replace('/\D/', '', (string) ($input['security_code'] ?? ''));
        $cardData = ['id' => $card->gateway_card_id];

        // Compra do titular com o CVV abre a recorrência; renovação continua a
        // cadeia quando ela existe. Sem nenhum dos dois, cobrança comum pelo
        // cartão salvo.
        $recurring = null;

        if ($customerInitiated && strlen((string) $securityCode) >= 3) {
            $cardData['security_code'] = $securityCode;
            $recurring = ['type' => 'INITIAL'];
        } elseif (! $customerInitiated && $card->gateway_first_charge_id) {
            $recurring = ['type' => 'SUBSEQUENT'];
        }

        $charge = [
            'reference_id' => $this->shortReference($reference, 64),
            'description' => mb_substr($description, 0, 64),
            'amount' => ['value' => $this->cents($amount), 'currency' => 'BRL'],
            'payment_method' => array_filter([
                'type' => 'CREDIT_CARD',
                'installments' => 1,
                'capture' => true,
                'soft_descriptor' => $this->softDescriptor(),
                'card' => $cardData,
                'holder' => ['name' => mb_substr((string) $card->holder_name, 0, 30), 'tax_id' => $card->gateway_customer_id ?: $this->document($user)],
            ]),
        ];

        if ($recurring) {
            $charge['recurring'] = $recurring;
        }

        $response = $this->request()
            ->withHeaders(['x-idempotency-key' => $idempotencyKey])
            ->post($this->baseUrl().'/orders', [
                ...$this->orderBase($user, $amount, $description, $reference, $card->gateway_customer_id),
                'charges' => [$charge],
            ]);

        $order = $this->orderOrFail($response);
        $payment = self::toPayment($order);

        if ($payment->status === 'approved' && $recurring && $recurring['type'] === 'INITIAL' && ($chargeId = $order['charges'][0]['id'] ?? null)) {
            $card->update(['gateway_first_charge_id' => (string) $chargeId]);
        }

        return $payment;
    }

    public function createPix(Authentication $user, float $amount, string $description, string $reference, Carbon $expiresAt, string $idempotencyKey, ?string $cpf = null): object
    {
        $document = $this->document($user, $cpf);

        if (! $document) {
            throw new PaymentMessageException(__('api-key::messages.pix_requires_document'));
        }

        $response = $this->request()
            ->withHeaders(['x-idempotency-key' => $idempotencyKey])
            ->post($this->baseUrl().'/orders', [
                ...$this->orderBase($user, $amount, $description, $reference, $document),
                'qr_codes' => [[
                    'amount' => ['value' => $this->cents($amount)],
                    'expiration_date' => $expiresAt->copy()->setTimezone('America/Sao_Paulo')->format('Y-m-d\TH:i:sP'),
                ]],
            ]);

        $order = $this->orderOrFail($response);
        $qr = (array) ($order['qr_codes'][0] ?? []);
        $links = collect((array) ($qr['links'] ?? []))->keyBy('rel');

        return $this->withPix(
            self::toPayment($order),
            $qr['text'] ?? null,
            $this->imageBase64($links['QRCODE.PNG']['href'] ?? null, ['Authorization' => 'Bearer '.$this->config('token')]),
        );
    }

    public function findPayment(string $id): ?object
    {
        $response = $this->request()->get($this->baseUrl()."/orders/{$id}");

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new \RuntimeException("PagBank order lookup failed with HTTP {$response->status()}");
        }

        return self::toPayment((array) $response->json());
    }

    /** Estorna a cobrança paga do pedido, inteira ou em parte. */
    public function refund(string $id, ?float $amount = null): string
    {
        $order = $this->request()->get($this->baseUrl()."/orders/{$id}");
        $charge = collect((array) $order->json('charges', []))->firstWhere('status', 'PAID');

        if (! $order->successful() || ! is_array($charge) || empty($charge['id'])) {
            throw new \RuntimeException("PagBank order {$id} has no paid charge to refund");
        }

        $value = $amount !== null ? $this->cents($amount) : (int) ($charge['amount']['value'] ?? 0);

        $response = $this->request()
            ->withHeaders(['x-idempotency-key' => 'refund-'.$charge['id'].'-'.$value])
            ->post($this->baseUrl()."/charges/{$charge['id']}/cancel", ['amount' => ['value' => $value]]);

        if (! $response->successful()) {
            logglyError()->withContext(['order_id' => $id, 'status' => $response->status(), 'body' => $this->safeBody($response)])
                ->log('PagBank refund failed');

            throw new \RuntimeException("PagBank refund failed with HTTP {$response->status()}");
        }

        return (string) $charge['id'];
    }

    /**
     * Confere o `x-authenticity-token`: sha256 de "<token>-<corpo>". O corpo é
     * o pedido inteiro, mas só o id dele é usado.
     */
    public function webhookPaymentIds(Request $request): array
    {
        $payload = (string) $request->getContent();
        $expected = hash('sha256', $this->config('token').'-'.$payload);

        if (! $this->configured() || ! hash_equals($expected, strtolower((string) $request->header('x-authenticity-token')))) {
            throw new AuthenticationException;
        }

        $id = $request->input('id');

        return is_string($id) && preg_match('/^ORDE_[A-Za-z0-9-]+$/', $id) ? [$id] : [];
    }

    /**
     * Assinada com o token da conta (`x-authenticity-token`).
     */
    public function webhooksAuthenticated(): bool
    {
        return true;
    }

    /**
     * Pedido do PagBank no formato de pagamento do pacote.
     *
     * @param  array<string, mixed>  $order
     */
    public static function toPayment(array $order): object
    {
        $charges = collect((array) ($order['charges'] ?? []));
        $charge = (array) ($charges->firstWhere('status', 'PAID') ?? $charges->last() ?? []);
        $qr = (array) ($order['qr_codes'][0] ?? []);
        $isPix = ($charge['payment_method']['type'] ?? null) === 'PIX' || ($charge === [] && $qr !== []);

        $refunded = (int) ($charge['amount']['summary']['refunded'] ?? 0);

        $status = match ($charge['status'] ?? null) {
            'PAID', 'AUTHORIZED' => 'approved',
            'DECLINED' => 'rejected',
            'CANCELED' => $refunded > 0 ? 'refunded' : 'rejected',
            'IN_ANALYSIS', 'WAITING' => 'in_process',
            default => $isPix
                ? (isset($qr['expiration_date']) && Carbon::parse($qr['expiration_date'])->isPast() ? 'rejected' : 'pending')
                : 'in_process',
        };

        $response = (array) ($charge['payment_response'] ?? []);

        return (object) [
            'id' => (string) ($order['id'] ?? ''),
            'status' => $status,
            'status_detail' => (string) ($response['message'] ?? $response['code'] ?? ''),
            'external_reference' => $order['reference_id'] ?? null,
            'transaction_amount' => round(((int) ($charge['amount']['value'] ?? $qr['amount']['value'] ?? 0)) / 100, 2),
            'payment_method_id' => $isPix ? 'pix' : strtolower((string) ($charge['payment_method']['card']['brand'] ?? 'credit_card')),
        ];
    }

    /** @return array<string, mixed> */
    private function orderBase(Authentication $user, float $amount, string $description, string $reference, ?string $document): array
    {
        $customer = [
            'name' => mb_substr((string) $user->name, 0, 120),
            'email' => strtolower((string) $user->email),
            'tax_id' => $document ?: $this->document($user),
        ];

        if ($phone = $this->phone($user)) {
            $customer['phones'] = [['country' => '55', 'area' => $phone['area_code'], 'number' => $phone['number'], 'type' => 'MOBILE']];
        }

        $order = [
            'reference_id' => $this->shortReference($reference, 64),
            'customer' => array_filter($customer),
            'items' => [[
                'reference_id' => mb_substr(explode('|', $reference)[0] ?: 'plan', 0, 64),
                'name' => mb_substr($description, 0, 64),
                'quantity' => 1,
                'unit_amount' => $this->cents($amount),
            ]],
        ];

        $url = url('api/v1/dashboard/checkout/webhook/pagbank');

        if (str_starts_with($url, 'https://')) {
            $order['notification_urls'] = [$url];
        }

        return $order;
    }

    private function publicKey(): ?string
    {
        if ($configured = $this->config('public_key')) {
            return (string) $configured;
        }

        if (! $this->configured()) {
            return null;
        }

        $cacheKey = 'api-key:pagbank:public-key:'.sha1($this->config('token').'|'.(int) $this->sandbox());

        if ($cached = Cache::get($cacheKey)) {
            return (string) $cached;
        }

        try {
            $response = $this->request()->get($this->baseUrl().'/public-keys/card');

            if (! $response->successful() || ! $response->json('public_key')) {
                $response = $this->request()->post($this->baseUrl().'/public-keys', ['type' => 'card']);
            }
        } catch (\Exception $e) {
            logglyWarning()->withContext(['error' => $e->getMessage()])->log('PagBank public key lookup failed');

            return null;
        }

        $key = $response->json('public_key');

        if (! is_string($key) || $key === '') {
            logglyWarning()->withContext(['status' => $response->status()])->log('PagBank public key lookup failed');

            return null;
        }

        Cache::forever($cacheKey, $key);

        return $key;
    }

    private function softDescriptor(): ?string
    {
        $text = (string) ($this->config('statement_descriptor') ?: config('app.name'));

        return mb_substr(trim(preg_replace('/[^A-Za-z0-9 ]/', '', Str::ascii($text)) ?: ''), 0, 17) ?: null;
    }

    /**
     * Recusa do cartão também vem como pedido (cobrança `DECLINED`).
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    private function orderOrFail(Response $response): array
    {
        if ($response->successful() && $response->json('id')) {
            return (array) $response->json();
        }

        logglyError()->withContext(['status' => $response->status(), 'body' => $this->safeBody($response)])
            ->log('PagBank order request failed');

        throw new \RuntimeException("PagBank order request failed with HTTP {$response->status()}");
    }

    /** O PagBank ecoa cobranças com o titular do cartão. */
    protected function safeBody(Response $response): ?array
    {
        $body = parent::safeBody($response);

        unset($body['charges'], $body['qr_codes'], $body['holder']);

        return $body;
    }

    private function baseUrl(): string
    {
        return $this->sandbox() ? 'https://sandbox.api.pagseguro.com' : 'https://api.pagseguro.com';
    }

    private function request(): PendingRequest
    {
        return Http::withToken((string) $this->config('token'))
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }
}
