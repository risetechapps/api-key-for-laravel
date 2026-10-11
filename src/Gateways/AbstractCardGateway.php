<?php

namespace RiseTechApps\ApiKey\Gateways;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RiseTechApps\ApiKey\Contracts\CardGateway;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;

/**
 * O que os drivers de gateway têm em comum: dados do cliente no formato que
 * os gateways brasileiros pedem, o QR do PIX embutido e o corte da referência
 * para quem limita o tamanho.
 */
abstract class AbstractCardGateway implements CardGateway
{
    protected function config(string $key, mixed $default = null): mixed
    {
        return config("api-key.{$this->name()}.{$key}", $default);
    }

    protected function sandbox(): bool
    {
        return (bool) $this->config('sandbox', false);
    }

    public function pixEnabled(): bool
    {
        return (bool) $this->config('pix.enabled', false) && $this->configured();
    }

    public function ownsPaymentId(?string $id): bool
    {
        return is_string($id) && str_starts_with($id, $this->paymentIdPrefix());
    }

    /** CPF só com dígitos, do que veio no formulário ou do perfil. */
    protected function document(Authentication $user, ?string $cpf = null): ?string
    {
        $digits = preg_replace('/\D/', '', (string) ($cpf ?: $user->cpf));

        return strlen((string) $digits) === 11 ? $digits : null;
    }

    /** @return array{area_code: string, number: string, full: string}|null */
    protected function phone(Authentication $user): ?array
    {
        $digits = (string) preg_replace('/\D/', '', (string) ($user->cellphone ?: $user->telephone));

        if (str_starts_with($digits, '55') && strlen($digits) > 11) {
            $digits = substr($digits, 2);
        }

        return strlen($digits) >= 10
            ? ['area_code' => substr($digits, 0, 2), 'number' => substr($digits, 2), 'full' => $digits]
            : null;
    }

    /**
     * Endereço do perfil, só quando completo: endereço pela metade arrisca
     * conflitar com o que o emissor tem.
     *
     * @return array{street: string, number: string, district: string, complement: string, city: string, state: string, zip: string}|null
     */
    protected function address(Authentication $user): ?array
    {
        $address = $user->address()->first();

        if (! $address) {
            return null;
        }

        $field = fn (string $key): string => trim((string) $address->getAttribute($key));
        $zip = (string) preg_replace('/\D/', '', $field('zip_code'));

        if ($field('address') === '' || $field('city') === '' || strlen($field('state')) !== 2 || strlen($zip) !== 8) {
            return null;
        }

        return [
            'street' => $field('address'),
            'number' => $field('number') ?: 'S/N',
            'district' => $field('district'),
            'complement' => $field('complement'),
            'city' => $field('city'),
            'state' => strtoupper($field('state')),
            'zip' => $zip,
        ];
    }

    protected function birthDate(Authentication $user): ?string
    {
        $birth = $user->getAttribute('birth_date');

        return $birth ? Carbon::parse($birth)->format('Y-m-d') : null;
    }

    /**
     * Referência no tamanho que o gateway aceita. Renovação e excedente viram
     * `rn-<assinatura>` e `ov-<assinatura>`, que o PaymentOutcomeService
     * entende. Compra cabe ou é resolvida pelo registro de espera, porque a
     * aprovação na hora não depende dela.
     */
    protected function shortReference(string $reference, int $max): string
    {
        if (strlen($reference) <= $max) {
            return $reference;
        }

        $parts = explode('|', $reference);

        if (in_array($parts[0], ['renewal', 'overage'], true) && isset($parts[3])) {
            return ($parts[0] === 'renewal' ? 'rn-' : 'ov-').$parts[3];
        }

        return substr(hash('sha256', $reference), 0, min($max, 40));
    }

    /**
     * Baixa a imagem do QR e devolve em base64, para o painel mostrar como no
     * Mercado Pago. Melhor esforço: sem ela ainda há o copia e cola.
     *
     * @param  array<string, string>  $headers
     */
    protected function imageBase64(?string $url, array $headers = []): ?string
    {
        if (! $url || ! str_starts_with($url, 'https://')) {
            return null;
        }

        try {
            $image = Http::withHeaders($headers)->timeout(10)->get($url);

            return $image->successful() ? base64_encode($image->body()) : null;
        } catch (\Exception) {
            return null;
        }
    }

    /** Objeto de pagamento com o QR no formato que o checkout e o excedente leem. */
    protected function withPix(object $payment, ?string $copyPaste, ?string $imageBase64, ?string $url = null): object
    {
        $payment->point_of_interaction = (object) ['transaction_data' => (object) [
            'qr_code' => $copyPaste,
            'qr_code_base64' => $imageBase64,
            'ticket_url' => $url,
        ]];

        return $payment;
    }

    /** @param array<string, mixed> $attributes */
    protected function storeCard(Authentication $user, array $attributes): UserCard
    {
        UserCard::where('authentication_id', $user->getKey())->update(['is_default' => false]);

        return UserCard::updateOrCreate(
            ['authentication_id' => $user->getKey(), 'gateway' => $this->name(), 'last_four' => (string) $attributes['last_four']],
            [...$attributes, 'gateway' => $this->name(), 'is_default' => true, 'gateway_first_charge_id' => null],
        );
    }

    protected function existingCustomerId(Authentication $user): ?string
    {
        return UserCard::where('authentication_id', $user->getKey())
            ->where('gateway', $this->name())
            ->whereNotNull('gateway_customer_id')
            ->value('gateway_customer_id');
    }

    protected function fullYear(mixed $year): string
    {
        $year = (int) $year;

        return (string) ($year < 100 ? 2000 + $year : $year);
    }

    /**
     * Corpo de erro sem dados de cliente, para o log.
     *
     * @return array<string, mixed>|null
     */
    protected function safeBody(Response $response): ?array
    {
        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        unset($body['customer'], $body['payer'], $body['items'], $body['devedor']);

        return $body;
    }

    protected function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    protected function idempotency(): string
    {
        return (string) Str::uuid();
    }
}
