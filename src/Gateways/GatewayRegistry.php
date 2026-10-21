<?php

namespace RiseTechApps\ApiKey\Gateways;

use RiseTechApps\ApiKey\Contracts\CardGateway;
use RiseTechApps\ApiKey\Services\PaymentSettingsService;

/**
 * Os gateways além do Mercado Pago, por nome, e quem é dono de cada id.
 */
class GatewayRegistry
{
    /** @var array<string, class-string<CardGateway>> */
    public const DRIVERS = [
        'pagarme' => PagarmeGateway::class,
        'stripe' => StripeGateway::class,
        'efi' => EfiGateway::class,
        'pagbank' => PagbankGateway::class,
    ];

    public const LABELS = [
        'mercadopago' => 'Mercado Pago',
        'pagarme' => 'Pagar.me',
        'stripe' => 'Stripe',
        'efi' => 'Efí',
        'pagbank' => 'PagBank',
    ];

    public static function get(string $name): ?CardGateway
    {
        $class = self::DRIVERS[$name] ?? null;

        return $class ? app($class) : null;
    }

    /** Driver do gateway ativo; null quando é o Mercado Pago. */
    public static function active(): ?CardGateway
    {
        return self::get(PaymentSettingsService::activeGateway());
    }

    /** Driver dono do id de pagamento; null para ids do Mercado Pago. */
    public static function forPaymentId(?string $id): ?CardGateway
    {
        foreach (array_keys(self::DRIVERS) as $name) {
            $driver = self::get($name);

            if ($driver?->ownsPaymentId($id)) {
                return $driver;
            }
        }

        return null;
    }

    /** @return list<CardGateway> */
    public static function all(): array
    {
        return array_values(array_filter(array_map(fn ($n) => self::get($n), array_keys(self::DRIVERS))));
    }
}
