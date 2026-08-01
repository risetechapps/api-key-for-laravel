<?php

namespace RiseTechApps\ApiKey\Services;

/**
 * Resultado da avaliação da política de reembolso.
 *
 * Carrega o motivo junto com o veredito porque quem cancela precisa saber por
 * que não recebeu o dinheiro de volta — "fora da janela de 7 dias" e "já usou
 * mais da metade das requisições" levam o cliente a conclusões diferentes, e
 * responder só `false` transformaria as duas na mesma dúvida no suporte.
 */
final readonly class RefundDecision
{
    public const REASON_ELIGIBLE = 'eligible';

    public const REASON_DISABLED = 'refund_disabled';

    public const REASON_NOT_PAID = 'nothing_to_refund';

    public const REASON_ALREADY_REFUNDED = 'already_refunded';

    public const REASON_WINDOW_EXPIRED = 'window_expired';

    public const REASON_USAGE_EXCEEDED = 'usage_exceeded';

    private function __construct(
        public bool $eligible,
        public string $reason,
        public float $amount = 0.0,
    ) {}

    public static function eligible(float $amount): self
    {
        return new self(true, self::REASON_ELIGIBLE, $amount);
    }

    public static function refused(string $reason): self
    {
        return new self(false, $reason);
    }

    /** Mensagem traduzida correspondente ao motivo. */
    public function message(): string
    {
        return __("api-key::messages.refund.{$this->reason}");
    }
}
