<?php

namespace RiseTechApps\ApiKey\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use RiseTechApps\ApiKey\Models\Plan\Plan;

/**
 * A renovação não pode ser cobrada automaticamente: o assinante renova pelo
 * painel, com PIX ou cartão.
 *
 * Só avisa e leva ao painel. Não leva QR code nem link de pagamento: e-mail
 * com cobrança dentro é o formato que filtros de spam e golpes exploram, e
 * uma entrega falha derrubaria a cobrança junto.
 */
class RenewalPaymentRequiredNotification extends Notification
{
    /**
     * @param  string  $reason  `no_card`, `card_out_of_use`, `pix` ou `cvv_required`.
     */
    public function __construct(
        public readonly Plan $plan,
        public readonly float $amount,
        public readonly ?Carbon $accessUntil,
        public readonly string $reason,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $valor = number_format($this->amount, 2, ',', '.');
        $ate = $this->accessUntil?->format('d/m/Y');

        $message = (new MailMessage)
            ->subject('Renove sua assinatura do plano '.$this->plan->name)
            ->greeting("Olá, {$notifiable->name}!")
            ->line("Sua assinatura do plano **{$this->plan->name}** ({$valor}) chegou ao fim do período e precisa ser renovada.");

        $message->line(match ($this->reason) {
            'cvv_required' => 'Por segurança, a renovação no cartão precisa da sua confirmação. Entre no painel e renove com PIX ou com o cartão.',
            'pix' => 'Como você paga com PIX, a renovação é feita por você no painel.',
            'card_out_of_use' => 'O cartão que você tinha salvo não é mais aceito para pagamento. Entre no painel, cadastre um cartão novo e renove, ou renove com PIX.',
            default => 'Não há cartão cadastrado para a renovação automática. Entre no painel e renove com PIX ou cartão.',
        });

        if ($ate) {
            $message->line("Seu acesso continua até **{$ate}**. Depois disso a API deixa de responder até a renovação.");
        }

        return $message
            ->action('Renovar no painel', url('/dashboard/billing'))
            ->line('Nunca enviamos cobranças por e-mail: o pagamento é sempre feito dentro do painel.')
            ->salutation('Atenciosamente, Equipe '.config('app.name'));
    }
}
