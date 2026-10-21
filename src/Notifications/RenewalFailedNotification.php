<?php

namespace RiseTechApps\ApiKey\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use RiseTechApps\ApiKey\Models\Plan\Plan;

/**
 * A cobrança da renovação falhou.
 *
 * Diz quando será a próxima tentativa, ou que não haverá outra, e o que fazer:
 * trocar o cartão. Atualizar o cartão dispara a nova tentativa na hora.
 */
class RenewalFailedNotification extends Notification
{
    public function __construct(
        public readonly Plan $plan,
        public readonly float $amount,
        public readonly int $attempt,
        public readonly ?Carbon $nextAttemptAt,
        public readonly ?string $reason = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $valor = number_format($this->amount, 2, ',', '.');

        $message = (new MailMessage)
            ->subject($this->nextAttemptAt ? 'Não conseguimos renovar sua assinatura' : 'Sua assinatura não foi renovada')
            ->greeting("Olá, {$notifiable->name}!")
            ->line("A cobrança de **R$ {$valor}** para renovar o plano **{$this->plan->name}** não foi aprovada.");

        if ($this->reason) {
            $message->line($this->reason);
        }

        $message->line($this->nextAttemptAt
            ? 'Vamos tentar de novo em **'.$this->nextAttemptAt->format('d/m/Y').'**. Para não perder o acesso, atualize seu cartão: a nova tentativa acontece na hora.'
            : 'Esta foi a última tentativa automática. Para continuar usando o serviço, contrate o plano novamente pelo painel.');

        return $message
            ->action('Atualizar forma de pagamento', url('/dashboard/billing'))
            ->salutation('Atenciosamente, Equipe '.config('app.name'));
    }
}
