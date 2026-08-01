<?php

namespace RiseTechApps\ApiKey\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

/**
 * Confirma a devolução do valor pago.
 *
 * Ao contrário do cancelamento simples, aqui o acesso termina na hora — e é
 * exatamente isso que o cliente precisa ler, junto do prazo de crédito na
 * fatura, que não depende do pacote e é a principal causa de ticket depois de
 * um estorno.
 */
class PlanRefundedNotification extends Notification
{
    public function __construct(
        public readonly Plan $plan,
        public readonly UserPlan $userPlan,
        public readonly float $amount,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $valor = number_format($this->amount, 2, ',', '.');

        return (new MailMessage)
            ->subject('Estorno concedido')
            ->greeting("Olá, {$notifiable->name}!")
            ->line("Cancelamos sua assinatura do plano **{$this->plan->name}** e devolvemos **R$ {$valor}**.")
            ->line('**O acesso foi encerrado agora.** Como o valor foi devolvido, o período não segue ativo — sua chave de API deixa de responder a partir deste momento.')
            ->line('O crédito aparece na fatura do cartão conforme o prazo da operadora, normalmente em até duas faturas.')
            ->action('Contratar novamente', url('/dashboard/billing'))
            ->line('Se quiser voltar, é só contratar um plano de novo pelo painel.')
            ->salutation('Atenciosamente, Equipe '.config('app.name'));
    }
}
