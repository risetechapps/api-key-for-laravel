<?php

namespace RiseTechApps\ApiKey\Enums;

/**
 * Formas de pagamento que o checkout do pacote realmente processa.
 *
 * PIX, boleto e transferência existiam aqui, mas nada no pacote os
 * implementava: `CheckoutController::process()` só monta pagamento com
 * `token` de cartão. Eram opções que a validação aceitava e o checkout não
 * sabia cobrar. Fluxo assíncrono (QR code / linha digitável, expiração,
 * ativação só na confirmação do webhook) é uma feature à parte — quando
 * existir, os casos voltam junto com ela.
 */
enum BillingMethod: string
{
    case CREDIT_CARD = 'credit_card';
    case DEBIT_CARD = 'debit_card';

    /**
     * Retorna o nome amigável do ciclo de cobrança.
     */
    public function label(): string
    {
        return match ($this) {
            self::CREDIT_CARD => 'Cartão de Crédito',
            self::DEBIT_CARD => 'Cartão de Debito',
        };
    }

    public static function values(): array
    {
        return array_map(fn($case) => $case->value, self::cases());
    }
}
