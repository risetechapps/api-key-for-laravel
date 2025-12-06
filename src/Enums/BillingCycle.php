<?php

namespace RiseTechApps\ApiKey\Enums;

enum BillingCycle: string
{
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';
    case ANNUALLY = 'annually';

    /**
     * Retorna o nome amigável do ciclo de cobrança.
     */
    public function label(): string
    {
        return match ($this) {
            self::WEEKLY => 'weekly',
            self::MONTHLY => 'monthly',
            self::ANNUALLY => 'annually',
        };
    }

    public static function values(): array
    {
        return array_map(fn($case) => $case->value, self::cases());
    }

    public static function convertInDays(self $billingCycle):int
    {
        return match ($billingCycle) {
            self::WEEKLY => 7,
            self::MONTHLY => 30,
            self::ANNUALLY => 365,
        };
    }
}
