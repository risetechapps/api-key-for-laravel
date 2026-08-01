<?php

namespace RiseTechApps\ApiKey\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

/**
 * Confirms a cancellation.
 *
 * Written to answer the three things a customer wonders about the moment they
 * click cancel: did it work, do I lose access now, and will I be charged again.
 * Silence after a cancellation is what generates the support ticket.
 */
class PlanCancelledNotification extends Notification
{
    public function __construct(
        public readonly Plan $plan,
        public readonly UserPlan $userPlan
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $accessUntil = $this->userPlan->end_date?->format('d/m/Y');

        return (new MailMessage)
            ->subject('Renovação cancelada')
            ->greeting("Olá, {$notifiable->name}!")
            ->line("A renovação automática do seu plano **{$this->plan->name}** foi cancelada.")
            ->line("**Você não perdeu o acesso.** Ele continua ativo até {$accessUntil}, o fim do período que você já pagou.")
            ->line('Nenhuma nova cobrança será feita.')
            ->action('Reativar renovação', url('/dashboard/billing'))
            ->line('Mudou de ideia? Você pode reativar a renovação pelo painel enquanto o período atual não terminar.')
            ->salutation('Atenciosamente, Equipe '.config('app.name'));
    }
}
