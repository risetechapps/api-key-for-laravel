<?php

namespace RiseTechApps\ApiKey\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

class PlanActivatedNotification extends Notification
{
    public function __construct(
        public readonly Plan $plan,
        public readonly UserPlan $userPlan,
        public readonly ?Plan $previousPlan = null
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isChange = $this->previousPlan !== null;

        $mail = (new MailMessage)
            ->subject($isChange ? 'Seu plano foi alterado' : 'Assinatura confirmada')
            ->greeting("Olá, {$notifiable->name}!");

        if ($isChange) {
            $mail->line("Seu plano foi alterado de **{$this->previousPlan->name}** para **{$this->plan->name}**.");
        } else {
            $mail->line("Sua assinatura do plano **{$this->plan->name}** foi ativada com sucesso.");
        }

        return $mail
            ->line("Limite de requisições: **{$this->plan->request_limit}** por ciclo.")
            ->line('Validade do período atual: '.optional($this->userPlan->end_date)->format('d/m/Y').'.')
            ->action('Acessar painel', url('/dashboard'))
            ->salutation('Atenciosamente, Equipe '.config('app.name'));
    }
}
