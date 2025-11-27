<?php

namespace RiseTechApps\ApiKey\Enums;

enum BillingMethod: string
{
    case CREDIT_CARD = 'credit_card';
    case DEBIT_CARD = 'debit_card';
    case PIX = 'pix';
    case BANK_SLIP = 'bank_slip';
    case BANK_TRANSFER = 'bank_transfer';

    /**
     * Retorna o nome amigável do ciclo de cobrança.
     */
    public function label(): string
    {
        return match ($this) {
            self::CREDIT_CARD => 'Cartão de Crédito',
            self::DEBIT_CARD => 'Cartão de Debito',
            self::PIX => 'Pix',
            self::BANK_SLIP => 'Boleto',
            self::BANK_TRANSFER => 'Transferência',
        };
    }

    public static function values(): array
    {
        return array_map(fn($case) => $case->value, self::cases());
    }
}
