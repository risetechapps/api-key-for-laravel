<?php

namespace RiseTechApps\ApiKey\Gateways;

use Illuminate\Http\Client\ConnectionException;
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
 * Efí (antiga Gerencianet).
 *
 * Cartão pela API de Cobranças. O navegador gera, com a biblioteca da Efí e o
 * identificador de conta, um `payment_token` com `reuse: true`: é ele que fica
 * salvo e cobra as próximas vezes sem CVV. A Efí não faz autorização de valor
 * zero, então o cartão só é validado na primeira cobrança.
 *
 * A Efí pede, na cobrança de cartão, CPF, telefone, data de nascimento e
 * endereço do titular: sem eles no perfil, a cobrança é recusada antes de ir.
 *
 * PIX pela API Pix, que exige o certificado da aplicação (mTLS) em toda
 * chamada, inclusive na autenticação.
 *
 * Ids no pacote: `efi_<charge_id>` (cartão) e `efipix_<txid>` (PIX).
 */
class EfiGateway extends AbstractCardGateway
{
    private const CARD_PREFIX = 'efi_';

    private const PIX_PREFIX = 'efipix_';

    public function name(): string
    {
        return 'efi';
    }

    /** Os dois formatos começam assim; os ids do Mercado Pago, nunca. */
    public function paymentIdPrefix(): string
    {
        return 'efi';
    }

    public function ownsPaymentId(?string $id): bool
    {
        return is_string($id) && (str_starts_with($id, self::CARD_PREFIX) || str_starts_with($id, self::PIX_PREFIX));
    }

    public function configured(): bool
    {
        return (string) $this->config('client_id') !== '' && (string) $this->config('client_secret') !== '';
    }

    public function pixEnabled(): bool
    {
        return parent::pixEnabled()
            && (string) $this->config('pix.key') !== ''
            && (string) $this->config('pix.certificate') !== '';
    }

    public function publicConfig(): array
    {
        return [
            'account_id' => $this->config('account_id'),
            'sandbox' => $this->sandbox(),
        ];
    }

    public function testConnection(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'message' => 'Client ID e Client Secret não configurados.'];
        }

        try {
            $this->cardToken(fresh: true);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $messages = ['Conexão com a API de Cobranças da Efí funcionando ('.($this->sandbox() ? 'homologação' : 'produção').').'];

        $accountId = trim((string) $this->config('account_id'));

        if ($accountId === '') {
            return ['ok' => false, 'message' => $messages[0].' Falta o identificador de conta, usado para gerar o token do cartão.'];
        }

        // O identificador (payee code) é um código de 32 caracteres. O número
        // da conta (ex.: 123456-7) é recusado pela Efí na hora de gerar o token.
        if (! preg_match('/^[0-9a-f]{32}$/i', $accountId)) {
            return ['ok' => false, 'message' => $messages[0].' O identificador de conta parece ser o número da conta. Use o código de 32 caracteres de API > Introdução > Identificador de conta.'];
        }

        if ($this->config('pix.enabled')) {
            try {
                $this->pixToken(fresh: true);
                $messages[] = 'API Pix com certificado funcionando.';

                if ($keyProblem = $this->pixKeyProblem()) {
                    return ['ok' => false, 'message' => implode(' ', $messages).' '.$keyProblem];
                }
            } catch (\RuntimeException $e) {
                return ['ok' => false, 'message' => $messages[0].' API Pix: '.$e->getMessage()];
            }
        }

        return ['ok' => true, 'message' => implode(' ', $messages)];
    }

    /**
     * Guarda o `payment_token` reutilizável. Bandeira, final e validade vêm do
     * navegador (`extra`), que é quem viu o cartão.
     */
    public function saveCard(Authentication $user, array $input): UserCard
    {
        $token = (string) $input['card_token'];
        $extra = (array) ($input['extra'] ?? []);
        $lastFour = substr((string) preg_replace('/\D/', '', (string) ($extra['card_mask'] ?? '')), -4);

        if (! preg_match('/^[A-Za-z0-9]{20,128}$/', $token) || strlen($lastFour) !== 4) {
            throw new PaymentMessageException(__('api-key::messages.invalid_payment_data'));
        }

        // Sem isto o cartão seria salvo e recusado na primeira cobrança, que
        // pode ser uma renovação às 3h da manhã.
        if (! $this->profileComplete($user, $input['cpf'] ?? null)) {
            throw new PaymentMessageException(__('api-key::messages.card_requires_full_profile'));
        }

        return $this->storeCard($user, [
            'last_four' => $lastFour,
            'holder_name' => (string) (($input['holder_name'] ?? null) ?: $user->name),
            'brand' => strtolower((string) ($extra['brand'] ?? 'outros')),
            'expiry_month' => str_pad((string) (int) ($extra['expiry_month'] ?? 1), 2, '0', STR_PAD_LEFT),
            'expiry_year' => $this->fullYear($extra['expiry_year'] ?? 0),
            // A Efí não tem cliente: o CPF do titular fica aqui para as
            // próximas cobranças, que o exigem.
            'gateway_customer_id' => $this->document($user, $input['cpf'] ?? null),
            'gateway_card_id' => $token,
        ]);
    }

    /** O token não tem como ser revogado na Efí; basta o pacote esquecê-lo. */
    public function deleteCard(UserCard $card): bool
    {
        return true;
    }

    public function chargeCard(UserCard $card, float $amount, string $reference, string $description, string $idempotencyKey, bool $customerInitiated = false, array $input = []): object
    {
        $user = $card->authentication;
        $cpf = $card->gateway_customer_id ?: ($user ? $this->document($user) : null);
        $phone = $user ? $this->phone($user) : null;
        $birth = $user ? $this->birthDate($user) : null;
        $address = $user ? $this->address($user) : null;

        if (! $user || ! $cpf || ! $phone || ! $birth || ! $address) {
            throw new PaymentMessageException(__('api-key::messages.card_requires_full_profile'));
        }

        $metadata = ['custom_id' => self::toCustomId($reference)];

        if ($url = $this->notificationUrl()) {
            $metadata['notification_url'] = $url;
        }

        // A Efí não tem chave de idempotência: a mesma tentativa reenviada é
        // devolvida do cache em vez de cobrar duas vezes.
        $cacheKey = 'api-key:efi:charge:'.sha1($idempotencyKey);

        if ($chargeId = Cache::get($cacheKey)) {
            return $this->findPayment(self::CARD_PREFIX.$chargeId)
                ?? throw new \RuntimeException("Efí charge {$chargeId} not found");
        }

        $response = $this->cardRequest()->post($this->cardBaseUrl().'/v1/charge/one-step', [
            'items' => [['name' => mb_substr($description, 0, 255), 'value' => $this->cents($amount), 'amount' => 1]],
            'metadata' => $metadata,
            'payment' => ['credit_card' => [
                'customer' => [
                    'name' => $user->name,
                    'cpf' => $cpf,
                    'email' => strtolower((string) $user->email),
                    'phone_number' => $phone['full'],
                    'birth' => $birth,
                ],
                'installments' => 1,
                'payment_token' => $card->gateway_card_id,
                'billing_address' => [
                    'street' => $address['street'],
                    'number' => $address['number'],
                    'neighborhood' => $address['district'] ?: 'Centro',
                    'zipcode' => $address['zip'],
                    'city' => $address['city'],
                    'complement' => $address['complement'] ?: null,
                    'state' => $address['state'],
                ],
            ]],
        ]);

        $data = (array) $response->json('data', []);

        if (! $response->successful() || empty($data['charge_id'])) {
            logglyError()->withContext(['status' => $response->status(), 'body' => $this->safeBody($response)])
                ->log('Efí card charge failed');

            // Recusa de validação (dado do cliente) vem como 400, sem cobrança:
            // não houve tentativa no cartão, mas também não há o que esperar.
            if ($response->status() === 400) {
                return (object) [
                    'id' => '',
                    'status' => 'rejected',
                    'status_detail' => (string) ($response->json('error_description.message') ?? $response->json('error_description') ?? 'invalid_data'),
                    'external_reference' => $reference,
                    'transaction_amount' => $amount,
                    'payment_method_id' => 'credit_card',
                ];
            }

            throw new \RuntimeException("Efí card charge failed with HTTP {$response->status()}");
        }

        Cache::put($cacheKey, $data['charge_id'], now()->addDay());

        return (object) [
            'id' => self::CARD_PREFIX.$data['charge_id'],
            'status' => self::cardStatus((string) ($data['status'] ?? '')),
            'status_detail' => (string) ($data['refusal']['reason'] ?? $data['status'] ?? ''),
            'external_reference' => $reference,
            'transaction_amount' => round(((int) ($data['total'] ?? $this->cents($amount))) / 100, 2),
            'payment_method_id' => strtolower((string) ($card->brand ?: 'credit_card')),
        ];
    }

    /**
     * PIX imediato. O txid sai da chave de idempotência, então a mesma
     * tentativa devolve o mesmo QR em vez de criar outro.
     */
    public function createPix(Authentication $user, float $amount, string $description, string $reference, Carbon $expiresAt, string $idempotencyKey, ?string $cpf = null): object
    {
        $document = $this->document($user, $cpf);

        if (! $document) {
            throw new PaymentMessageException(__('api-key::messages.pix_requires_document'));
        }

        $txid = substr(hash('sha256', 'efi|'.$idempotencyKey), 0, 32);

        $response = $this->pix(fn (PendingRequest $http) => $http->put($this->pixBaseUrl()."/v2/cob/{$txid}", [
            'calendario' => ['expiracao' => max(60, (int) now()->diffInSeconds($expiresAt, false))],
            'devedor' => ['cpf' => $document, 'nome' => mb_substr((string) $user->name, 0, 200)],
            'valor' => ['original' => number_format($amount, 2, '.', '')],
            'chave' => (string) $this->config('pix.key'),
            'solicitacaoPagador' => mb_substr($description, 0, 140),
            'infoAdicionais' => [['nome' => 'referencia', 'valor' => mb_substr($reference, 0, 200)]],
        ]));

        // Txid já usado: é a mesma tentativa, reenviada, e o QR é o mesmo.
        // Qualquer outra recusa (chave errada, CPF inválido) vale como está.
        if (! $response->successful() && $this->isDuplicateTxid($response)) {
            $response = $this->pix(fn (PendingRequest $http) => $http->get($this->pixBaseUrl()."/v2/cob/{$txid}"));
        }

        if (! $response->successful() || ! $response->json('txid')) {
            logglyError()->withContext([
                'status' => $response->status(),
                'error' => $response->json('nome'),
                'message' => $response->json('mensagem'),
            ])->log('Efí PIX creation failed');

            throw new \RuntimeException('Efí PIX creation failed: '.($response->json('mensagem') ?: "HTTP {$response->status()}"));
        }

        $cob = (array) $response->json();
        $image = null;

        if ($locId = $cob['loc']['id'] ?? null) {
            try {
                $qr = $this->pix(fn (PendingRequest $http) => $http->get($this->pixBaseUrl()."/v2/loc/{$locId}/qrcode"));
                $image = $qr->successful() ? preg_replace('/^data:image\/[a-z]+;base64,/', '', (string) $qr->json('imagemQrcode')) : null;
            } catch (\RuntimeException) {
                $image = null;
            }
        }

        return $this->withPix(self::pixToPayment($cob), $cob['pixCopiaECola'] ?? null, $image ?: null);
    }

    public function findPayment(string $id): ?object
    {
        if (str_starts_with($id, self::PIX_PREFIX)) {
            $txid = substr($id, strlen(self::PIX_PREFIX));
            $response = $this->pix(fn (PendingRequest $http) => $http->get($this->pixBaseUrl()."/v2/cob/{$txid}"));

            if ($response->status() === 404) {
                return null;
            }

            if (! $response->successful()) {
                throw new \RuntimeException("Efí PIX lookup failed with HTTP {$response->status()}");
            }

            return self::pixToPayment((array) $response->json());
        }

        $chargeId = substr($id, strlen(self::CARD_PREFIX));
        $response = $this->cardRequest()->get($this->cardBaseUrl()."/v1/charge/{$chargeId}");

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new \RuntimeException("Efí charge lookup failed with HTTP {$response->status()}");
        }

        $data = (array) $response->json('data', []);

        return (object) [
            'id' => self::CARD_PREFIX.($data['charge_id'] ?? $chargeId),
            'status' => self::cardStatus((string) ($data['status'] ?? '')),
            'status_detail' => (string) ($data['payment']['credit_card']['refusal']['reason'] ?? $data['status'] ?? ''),
            'external_reference' => self::fromCustomId($data['custom_id'] ?? ($data['metadata']['custom_id'] ?? null)),
            'transaction_amount' => round(((int) ($data['total'] ?? 0)) / 100, 2),
            'payment_method_id' => 'credit_card',
        ];
    }

    public function refund(string $id, ?float $amount = null): string
    {
        if (str_starts_with($id, self::PIX_PREFIX)) {
            return $this->refundPix(substr($id, strlen(self::PIX_PREFIX)), $amount);
        }

        $chargeId = substr($id, strlen(self::CARD_PREFIX));
        $body = $amount !== null ? ['amount' => $this->cents($amount)] : [];

        $response = $this->cardRequest()->post($this->cardBaseUrl()."/v1/charge/card/{$chargeId}/refund", $body);

        if (! $response->successful()) {
            logglyError()->withContext(['payment_id' => $id, 'status' => $response->status(), 'body' => $this->safeBody($response)])
                ->log('Efí card refund failed');

            throw new \RuntimeException("Efí refund failed with HTTP {$response->status()}");
        }

        return 'efi-refund-'.$chargeId;
    }

    /**
     * A Efí manda só um token de notificação. A consulta dele, com as
     * credenciais da conta, é o que diz quais cobranças mudaram: um token
     * forjado não é encontrado e não aponta nada.
     */
    public function webhookPaymentIds(Request $request): array
    {
        $token = $request->input('notification');

        if (! is_string($token) || ! preg_match('/^[A-Za-z0-9-]{8,128}$/', $token) || ! $this->configured()) {
            return [];
        }

        $response = $this->cardRequest()->get($this->cardBaseUrl()."/v1/notification/{$token}");

        if ($response->status() >= 500) {
            throw new \RuntimeException("Efí notification lookup failed with HTTP {$response->status()}");
        }

        if (! $response->successful()) {
            return [];
        }

        return collect((array) $response->json('data', []))
            ->map(fn ($event) => $event['identifiers']['charge_id'] ?? null)
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => self::CARD_PREFIX.$id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Chave aleatória (EVP) dá para conferir na lista da conta. E-mail, CPF ou
     * telefone a Efí só confere na primeira cobrança.
     */
    private function pixKeyProblem(): ?string
    {
        $key = trim((string) $this->config('pix.key'));

        if ($key === '') {
            return 'Falta a chave PIX.';
        }

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key)) {
            return null;
        }

        try {
            $response = $this->pix(fn (PendingRequest $http) => $http->get($this->pixBaseUrl().'/v2/gn/evp'));
        } catch (\RuntimeException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $keys = array_map('strtolower', (array) $response->json('chaves', []));

        return in_array(strtolower($key), $keys, true)
            ? null
            : 'A chave PIX informada não está cadastrada nesta conta Efí'.($this->sandbox() ? ' de homologação.' : '.');
    }

    private function isDuplicateTxid(Response $response): bool
    {
        return $response->status() === 409
            || str_contains(strtolower((string) $response->json('nome')), 'txid_duplicado')
            || str_contains(strtolower((string) $response->json('mensagem')), 'já existe');
    }

    /**
     * A Efí só aceita letras, números, `_`, `-` e espaço no `custom_id`, e
     * responde HTTP 500 a qualquer outro caractere. A referência do pacote
     * separa as partes com `|`, que vai como `_` e volta na leitura: os ids
     * (UUID, ULID ou números) nunca têm `_`.
     */
    private static function toCustomId(string $reference): string
    {
        return mb_substr((string) preg_replace('/[^A-Za-z0-9_\- ]/', '', str_replace('|', '_', $reference)), 0, 255);
    }

    private static function fromCustomId(?string $customId): ?string
    {
        return $customId === null ? null : str_replace('_', '|', $customId);
    }

    private function profileComplete(Authentication $user, ?string $cpf = null): bool
    {
        return $this->document($user, $cpf) && $this->phone($user) && $this->birthDate($user) && $this->address($user);
    }

    private static function cardStatus(string $status): string
    {
        return match ($status) {
            'approved', 'paid', 'settled' => 'approved',
            'unpaid', 'canceled', 'expired' => 'rejected',
            'refunded' => 'refunded',
            default => 'in_process',
        };
    }

    /** @param array<string, mixed> $cob */
    private static function pixToPayment(array $cob): object
    {
        $created = isset($cob['calendario']['criacao']) ? Carbon::parse($cob['calendario']['criacao']) : null;
        $expired = $created && $created->copy()->addSeconds((int) ($cob['calendario']['expiracao'] ?? 0))->isPast();
        $refunded = collect((array) ($cob['pix'] ?? []))->contains(fn ($pix) => ! empty($pix['devolucoes']));

        $status = match (true) {
            ($cob['status'] ?? null) === 'CONCLUIDA' => $refunded ? 'refunded' : 'approved',
            str_starts_with((string) ($cob['status'] ?? ''), 'REMOVIDA') => 'rejected',
            $expired => 'rejected',
            default => 'pending',
        };

        $reference = collect((array) ($cob['infoAdicionais'] ?? []))->firstWhere('nome', 'referencia')['valor'] ?? null;

        return (object) [
            'id' => self::PIX_PREFIX.($cob['txid'] ?? ''),
            'status' => $status,
            'status_detail' => $expired && $status === 'rejected' ? 'expired' : strtolower((string) ($cob['status'] ?? '')),
            'external_reference' => $reference,
            'transaction_amount' => round((float) ($cob['valor']['original'] ?? 0), 2),
            'payment_method_id' => 'pix',
        ];
    }

    private function refundPix(string $txid, ?float $amount): string
    {
        $cob = $this->pix(fn (PendingRequest $http) => $http->get($this->pixBaseUrl()."/v2/cob/{$txid}"));
        $pix = $cob->json('pix.0');

        if (! $cob->successful() || ! is_array($pix) || empty($pix['endToEndId'])) {
            throw new \RuntimeException("Efí PIX {$txid} not found for refund");
        }

        $refundId = substr(hash('sha256', 'refund|'.$txid), 0, 32);
        $value = $amount ?? (float) $pix['valor'];

        $response = $this->pix(fn (PendingRequest $http) => $http->put(
            $this->pixBaseUrl()."/v2/pix/{$pix['endToEndId']}/devolucao/{$refundId}",
            ['valor' => number_format($value, 2, '.', '')],
        ));

        if (! $response->successful()) {
            logglyError()->withContext(['txid' => $txid, 'status' => $response->status(), 'body' => $this->safeBody($response)])
                ->log('Efí PIX refund failed');

            throw new \RuntimeException("Efí PIX refund failed with HTTP {$response->status()}");
        }

        return (string) ($response->json('rtrId') ?? $refundId);
    }

    private function notificationUrl(): ?string
    {
        $url = url('api/v1/dashboard/checkout/webhook/efi');

        return str_starts_with($url, 'https://') ? $url : null;
    }

    private function cardBaseUrl(): string
    {
        return $this->sandbox() ? 'https://cobrancas-h.api.efipay.com.br' : 'https://cobrancas.api.efipay.com.br';
    }

    private function pixBaseUrl(): string
    {
        return $this->sandbox() ? 'https://pix-h.api.efipay.com.br' : 'https://pix.api.efipay.com.br';
    }

    private function cardRequest(): PendingRequest
    {
        return Http::withToken($this->cardToken())->acceptJson()->asJson()->timeout(30);
    }

    /** Token da API de Cobranças (vale 10 minutos; guardado por 8). */
    private function cardToken(bool $fresh = false): string
    {
        $key = 'api-key:efi:token:cobrancas:'.sha1($this->config('client_id').'|'.$this->config('client_secret').'|'.(int) $this->sandbox());

        if (! $fresh && ($token = Cache::get($key))) {
            return (string) $token;
        }

        try {
            $response = Http::withBasicAuth((string) $this->config('client_id'), (string) $this->config('client_secret'))
                ->acceptJson()
                ->timeout(15)
                ->post($this->cardBaseUrl().'/v1/authorize', ['grant_type' => 'client_credentials']);
        } catch (\Exception $e) {
            throw new \RuntimeException('Sem resposta da Efí: '.$e->getMessage());
        }

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new \RuntimeException($response->status() === 401
                ? 'Client ID ou Client Secret recusados pela Efí.'
                : "Efí respondeu HTTP {$response->status()} na autenticação.");
        }

        Cache::put($key, (string) $response->json('access_token'), now()->addMinutes(8));

        return (string) $response->json('access_token');
    }

    /** Token da API Pix, autenticado com o certificado. */
    private function pixToken(bool $fresh = false): string
    {
        $key = 'api-key:efi:token:pix:'.sha1($this->config('client_id').'|'.$this->config('client_secret').'|'.(int) $this->sandbox().'|'.sha1((string) $this->config('pix.certificate')));

        if (! $fresh && ($token = Cache::get($key))) {
            return (string) $token;
        }

        $response = $this->withCertificate(fn (array $options) => Http::withOptions($options)
            ->withBasicAuth((string) $this->config('client_id'), (string) $this->config('client_secret'))
            ->acceptJson()
            ->timeout(15)
            ->post($this->pixBaseUrl().'/oauth/token', ['grant_type' => 'client_credentials']));

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new \RuntimeException($response->status() === 401
                ? 'Credenciais ou certificado recusados pela API Pix da Efí.'
                : "API Pix da Efí respondeu HTTP {$response->status()} na autenticação.");
        }

        Cache::put($key, (string) $response->json('access_token'), now()->addMinutes(max(1, (int) floor(((int) $response->json('expires_in', 3600)) / 60) - 2)));

        return (string) $response->json('access_token');
    }

    /**
     * Chamada à API Pix: certificado no TLS e o token no cabeçalho.
     *
     * @param  callable(PendingRequest): Response  $call
     */
    private function pix(callable $call): Response
    {
        $token = $this->pixToken();

        return $this->withCertificate(fn (array $options) => $call(
            Http::withOptions($options)->withToken($token)->acceptJson()->asJson()->timeout(30)
        ));
    }

    /**
     * Grava o certificado num arquivo temporário (0600), só pelo tempo da
     * chamada: o curl precisa de caminho de arquivo, e a chave privada não
     * fica no disco.
     *
     * Aceita .p12 (convertido para PEM, porque o OpenSSL 3 nem sempre abre
     * .p12 antigos direto no curl) ou PEM.
     *
     * @template T
     *
     * @param  callable(array<string, mixed>): T  $call
     * @return T
     */
    private function withCertificate(callable $call): mixed
    {
        $raw = base64_decode((string) $this->config('pix.certificate'), true);

        if ($raw === false || $raw === '') {
            throw new \RuntimeException('Certificado da Efí não configurado.');
        }

        $password = (string) $this->config('pix.certificate_password');
        $options = [];

        if (str_contains($raw, '-----BEGIN')) {
            $pem = $raw;
        } elseif (openssl_pkcs12_read($raw, $parts, $password)) {
            $pem = $parts['cert'].($parts['pkey'] ?? '').implode('', (array) ($parts['extracerts'] ?? []));
        } else {
            // O PHP não abriu: deixa o curl tentar o .p12 como está.
            $pem = $raw;
            $options['curl'] = [CURLOPT_SSLCERTTYPE => 'P12'];
        }

        $path = tempnam(sys_get_temp_dir(), 'efi-');

        if ($path === false) {
            throw new \RuntimeException('Não foi possível preparar o certificado da Efí.');
        }

        try {
            chmod($path, 0600);
            file_put_contents($path, $pem);
            $options['cert'] = isset($options['curl']) && $password !== '' ? [$path, $password] : $path;

            return $call($options);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Sem resposta da API Pix da Efí: '.Str::limit($e->getMessage(), 200));
        } finally {
            @unlink($path);
        }
    }
}
