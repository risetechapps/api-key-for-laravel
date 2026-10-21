<?php

namespace RiseTechApps\ApiKey\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

class UsageThresholdNotification extends Notification
{
    public function __construct(
        public readonly Plan $plan,
        public readonly UserPlan $userPlan,
        public readonly int $requestsUsed,
        public readonly int $requestsLimit,
        public readonly int $threshold
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $remaining = max(0, $this->requestsLimit - $this->requestsUsed);

        return (new MailMessage)
            ->subject("Você já usou {$this->threshold}% do seu limite")
            ->greeting("Olá, {$notifiable->name}!")
            ->line("Você já consumiu **{$this->requestsUsed} / {$this->requestsLimit}** requisições do plano **{$this->plan->name}** neste ciclo.")
            ->line("Restam aproximadamente **{$remaining}** requisições antes do bloqueio.")
            ->line('Se precisar de mais capacidade, considere fazer upgrade do plano.')
            ->action('Ver planos', url('/dashboard/plans'))
            ->salutation('Atenciosamente, Equipe '.config('app.name'));
    }
}
