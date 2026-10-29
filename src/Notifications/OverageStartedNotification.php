<?php

namespace RiseTechApps\ApiKey\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Notifications\Concerns\InformationalNotice;

/**
 * A conta passou do limite do plano e as requisições agora são excedente.
 */
class OverageStartedNotification extends Notification
{
    use InformationalNotice;

    public function __construct(
        public readonly Plan $plan,
        public readonly UserPlan $userPlan,
        public readonly ?int $extraAllowed,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $preco = number_format((float) $this->plan->overage_price, 2, ',', '.');

        $message = (new MailMessage)
            ->subject('Você passou do limite do plano '.$this->plan->name)
            ->greeting("Olá, {$notifiable->name}!")
            ->line("Sua conta usou as **{$this->plan->request_limit} requisições** do plano neste período. As próximas continuam funcionando como **excedente**, a **R$ {$preco} por requisição**, cobradas no fim do período.");

        if ($this->extraAllowed !== null) {
            $message->line("O excedente para em **{$this->extraAllowed} requisições**; depois disso a API responde 429 até o próximo período.");
        }

        return $message
            ->line('Para não ter cobrança extra, desligue o excedente no painel: a API passa a responder 429 no limite do plano.')
            ->action('Gerenciar excedente', url('/dashboard/billing'))
            ->salutation('Atenciosamente, Equipe '.config('app.name'));
    }
}
