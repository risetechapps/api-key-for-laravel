<?php

namespace RiseTechApps\ApiKey\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

class GracePeriodStartedNotification extends Notification
{
    public function __construct(
        public readonly Plan $plan,
        public readonly UserPlan $userPlan,
        public readonly int $gracePeriodDays,
        public readonly \DateTime $gracePeriodEndDate
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $endDate = \Carbon\Carbon::instance($this->gracePeriodEndDate)->format('d/m/Y');

        return (new MailMessage)
            ->subject('Seu plano expirou — período de tolerância ativo')
            ->greeting("Olá, {$notifiable->name}!")
            ->line("Seu plano **{$this->plan->name}** expirou, mas você ainda tem acesso durante o período de tolerância.")
            ->line("Você tem **{$this->gracePeriodDays} dias** para renovar sua assinatura (até {$endDate}).")
            ->action('Renovar agora', url('/dashboard/plans'))
            ->line('Após esse prazo, o acesso será suspenso automaticamente.')
            ->salutation('Atenciosamente, Equipe ' . config('app.name'));
    }
}
