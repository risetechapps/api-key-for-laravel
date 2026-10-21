<?php

namespace RiseTechApps\ApiKey\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

class RequestLimitReachedNotification extends Notification
{
    public function __construct(
        public readonly Plan $plan,
        public readonly UserPlan $userPlan,
        public readonly int $requestsUsed,
        public readonly int $requestsLimit
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Limite de requisições atingido')
            ->greeting("Olá, {$notifiable->name}!")
            ->line("Você atingiu o limite de requisições do plano **{$this->plan->name}**.")
            ->line("Uso atual: **{$this->requestsUsed} / {$this->requestsLimit}** requisições no ciclo.")
            ->line('Novas chamadas à API serão bloqueadas até a renovação do ciclo ou o upgrade do plano.')
            ->action('Fazer upgrade', url('/dashboard/plans'))
            ->salutation('Atenciosamente, Equipe '.config('app.name'));
    }
}
