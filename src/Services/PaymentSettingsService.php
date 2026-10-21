<?php

namespace RiseTechApps\ApiKey\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RiseTechApps\ApiKey\Gateways\GatewayRegistry;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Setting\Setting;

/**
 * Configuração dos gateways de pagamento editada pelo painel admin.
 *
 * O que estiver salvo no banco vale por cima do `.env`: no boot (e antes de
 * cada job da fila, porque um worker vive por horas) os valores salvos são
 * aplicados na config `api-key.*`. Assim o resto do pacote continua lendo
 * `config('api-key.mercadopago.access_token')` sem saber de onde veio.
 *
 * Credenciais nunca voltam ao painel: a tela recebe só se há valor e os
 * últimos caracteres.
 */
class PaymentSettingsService
{
    private const CACHE_KEY = 'api-key:payment-settings';

    public const GATEWAYS = ['mercadopago', 'stripe', 'efi', 'pagbank', 'pagarme'];

    /** Tamanho máximo do certificado da Efí, em base64. */
    private const MAX_CERTIFICATE = 65536;

    /**
     * Chaves editáveis, relativas a `api-key.`, com o tipo e se são segredo.
     *
     * @var array<string, array{type: string, secret?: bool}>
     */
    public const FIELDS = [
        'payment.gateway' => ['type' => 'gateway'],

        'mercadopago.public_key' => ['type' => 'string'],
        'mercadopago.access_token' => ['type' => 'string', 'secret' => true],
        'mercadopago.webhook_secret' => ['type' => 'string', 'secret' => true],
        'mercadopago.notification_url' => ['type' => 'url'],
        'mercadopago.pix.enabled' => ['type' => 'bool'],
        'mercadopago.pix.expiration_minutes' => ['type' => 'int'],
        'mercadopago.automatic_payments.enabled' => ['type' => 'bool'],

        'stripe.publishable_key' => ['type' => 'string'],
        'stripe.secret_key' => ['type' => 'string', 'secret' => true],
        'stripe.webhook_secret' => ['type' => 'string', 'secret' => true],
        'stripe.statement_descriptor' => ['type' => 'string'],
        'stripe.pix.enabled' => ['type' => 'bool'],
        'stripe.pix.expiration_minutes' => ['type' => 'int'],

        'efi.client_id' => ['type' => 'string'],
        'efi.client_secret' => ['type' => 'string', 'secret' => true],
        'efi.account_id' => ['type' => 'string'],
        'efi.sandbox' => ['type' => 'bool'],
        'efi.pix.enabled' => ['type' => 'bool'],
        'efi.pix.key' => ['type' => 'string'],
        'efi.pix.certificate' => ['type' => 'certificate', 'secret' => true],
        'efi.pix.certificate_password' => ['type' => 'string', 'secret' => true],
        'efi.pix.expiration_minutes' => ['type' => 'int'],

        'pagbank.token' => ['type' => 'string', 'secret' => true],
        'pagbank.public_key' => ['type' => 'string'],
        'pagbank.sandbox' => ['type' => 'bool'],
        'pagbank.statement_descriptor' => ['type' => 'string'],
        'pagbank.pix.enabled' => ['type' => 'bool'],
        'pagbank.pix.expiration_minutes' => ['type' => 'int'],

        'pagarme.public_key' => ['type' => 'string'],
        'pagarme.secret_key' => ['type' => 'string', 'secret' => true],
        'pagarme.webhook_user' => ['type' => 'string'],
        'pagarme.webhook_password' => ['type' => 'string', 'secret' => true],
        'pagarme.statement_descriptor' => ['type' => 'string'],
        'pagarme.pix.enabled' => ['type' => 'bool'],
        'pagarme.pix.expiration_minutes' => ['type' => 'int'],

        'spa.brand.name' => ['type' => 'string'],
        'spa.brand.color' => ['type' => 'color'],
        'spa.brand.logo' => ['type' => 'url'],
    ];

    /**
     * Aplica os valores salvos na config. Silencioso quando a tabela ainda
     * não existe (antes da migration) ou o banco não responde: aí vale o .env.
     */
    public static function applyToConfig(): void
    {
        try {
            foreach (self::stored() as $key => $value) {
                config(["api-key.{$key}" => $value]);
            }
        } catch (\Throwable) {
            // Sem banco, sem tabela ou APP_KEY trocada: segue com o .env.
        }
    }

    /** @return array<string, mixed> */
    private static function stored(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            if (! Schema::hasTable('api_key_settings')) {
                return [];
            }

            return Setting::whereIn('key', array_keys(self::FIELDS))
                ->get()
                ->mapWithKeys(fn (Setting $s) => [$s->key => $s->value])
                ->all();
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function activeGateway(): string
    {
        $gateway = (string) config('api-key.payment.gateway', 'mercadopago');

        return in_array($gateway, self::GATEWAYS, true) ? $gateway : 'mercadopago';
    }

    /**
     * Estado para o painel: valores comuns como estão, segredos mascarados.
     *
     * @return array<string, mixed>
     */
    public function forPanel(): array
    {
        $values = [];

        foreach (self::FIELDS as $key => $meta) {
            $value = config("api-key.{$key}");

            if ($meta['type'] === 'certificate') {
                // Do certificado só interessa saber se existe.
                $value = ['set' => is_string($value) && $value !== '', 'hint' => null];
            } elseif ($meta['secret'] ?? false) {
                $value = [
                    'set' => is_string($value) && $value !== '',
                    'hint' => is_string($value) && strlen($value) > 4 ? '…'.substr($value, -4) : null,
                ];
            } elseif ($meta['type'] === 'bool') {
                $value = filter_var($value, FILTER_VALIDATE_BOOL);
            } elseif ($meta['type'] === 'int') {
                $value = $value === null ? null : (int) $value;
            }

            $values[$key] = $value;
        }

        $stored = Schema::hasTable('api_key_settings')
            ? Setting::pluck('key')->all()
            : [];

        return [
            'values' => $values,
            // Quais chaves vêm do painel (as demais vêm do .env).
            'from_panel' => array_values(array_intersect(array_keys(self::FIELDS), $stored)),
            'webhooks' => [
                'mercadopago' => url('api/v1/dashboard/checkout/webhook'),
                ...collect(GatewayRegistry::DRIVERS)->keys()
                    ->mapWithKeys(fn (string $name) => [$name => url("api/v1/dashboard/checkout/webhook/{$name}")])
                    ->all(),
            ],
            'gateways' => collect(self::GATEWAYS)->map(fn (string $name) => [
                'key' => $name,
                'label' => GatewayRegistry::LABELS[$name] ?? $name,
                'configured' => $name === 'mercadopago'
                    ? (string) config('api-key.mercadopago.access_token') !== ''
                    : (bool) GatewayRegistry::get($name)?->configured(),
                // Fora do seletor, a não ser que já seja o ativo.
                'hidden' => in_array($name, (array) config('api-key.payment.hidden_gateways', []), true) && $name !== self::activeGateway(),
            ])->values()->all(),
        ];
    }

    /**
     * Grava as chaves enviadas. Segredo em branco é ignorado (mantém o atual);
     * `null` explícito apaga a linha e devolve a chave ao .env.
     *
     * @param  array<string, mixed>  $input
     * @return list<string> Chaves alteradas.
     */
    public function update(array $input, ?Authentication $actor = null): array
    {
        $changed = [];

        foreach ($input as $key => $value) {
            if (! isset(self::FIELDS[$key])) {
                continue;
            }

            $meta = self::FIELDS[$key];

            if (($meta['secret'] ?? false) && $value === '') {
                continue;
            }

            if ($value === null) {
                if (Setting::where('key', $key)->delete() > 0) {
                    $changed[] = $key;
                }

                continue;
            }

            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $this->cast($key, $meta['type'], $value), 'updated_by' => $actor?->getKey()],
            );

            $changed[] = $key;
        }

        self::forget();
        self::applyToConfig();

        if ($changed) {
            // Só os nomes: o valor de uma credencial não vai para a auditoria.
            AuditLogger::record('admin.payment_settings_updated', null, ['keys' => $changed], $actor);
        }

        return $changed;
    }

    /**
     * Certificado .p12 em base64, como o painel envia. Só confere que é
     * base64 de tamanho razoável; se abre com a senha, o teste de conexão diz.
     */
    private function certificate(string $key, mixed $value): string
    {
        $value = is_string($value) ? preg_replace('/^data:[^,]*,/', '', trim($value)) : null;

        if (! is_string($value) || strlen($value) > self::MAX_CERTIFICATE || base64_decode($value, true) === false) {
            throw ValidationException::withMessages([$key => 'Envie o certificado .p12 da aplicação.']);
        }

        return $value;
    }

    private function cast(string $key, string $type, mixed $value): mixed
    {
        $fail = fn (string $message) => throw ValidationException::withMessages([$key => $message]);

        return match ($type) {
            'gateway' => in_array($value, self::GATEWAYS, true) ? $value : $fail('Gateway inválido.'),
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $fail('Valor inválido.'),
            'int' => filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : $fail('Número inválido.'),
            'certificate' => $this->certificate($key, $value),
            'color' => is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : $fail('Informe a cor em hexadecimal, como #6366f1.'),
            'url' => $value === '' ? null : (filter_var($value, FILTER_VALIDATE_URL) && str_starts_with((string) $value, 'https://') ? trim((string) $value) : $fail('Informe uma URL HTTPS.')),
            default => is_scalar($value) ? trim((string) $value) : $fail('Valor inválido.'),
        };
    }
}
