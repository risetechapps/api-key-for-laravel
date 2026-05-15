<?php

namespace RiseTechApps\ApiKey\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

class PlanExpiredNotification extends Notification
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
        return (new MailMessage)
            ->subject('Seu acesso foi suspenso')
            ->greeting("Olá, {$notifiable->name}!")
            ->line("Seu plano **{$this->plan->name}** expirou e o período de tolerância encerrou.")
            ->line('Seu acesso à API foi suspenso. Para restabelecer o acesso, renove sua assinatura.')
            ->action('Renovar assinatura', url('/dashboard/plans'))
            ->line('Se tiver dúvidas, entre em contato com nosso suporte.')
            ->salutation('Atenciosamente, Equipe ' . config('app.name'));
    }
}
