<?php

namespace RiseTechApps\ApiKey\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RiseTechApps\ApiKey\Models\ApiKey\ApiKey;
use RiseTechApps\ApiKey\Notifications\Concerns\InformationalNotice;

class ApiKeyExpiringNotification extends Notification
{
    use InformationalNotice;

    public function __construct(
        public readonly ApiKey $apiKey,
        public readonly int $daysLeft
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->apiKey->name ?: 'sem nome';
        $when = $this->daysLeft <= 1 ? 'amanhã' : "em {$this->daysLeft} dias";

        return (new MailMessage)
            ->subject("Sua chave de API vence {$when}")
            ->greeting("Olá, {$notifiable->name}!")
            ->line("A chave de API **{$name}** vence {$when}, em **{$this->apiKey->expires_at?->format('d/m/Y H:i')}**.")
            ->line('Depois disso, toda chamada feita com ela será recusada. Crie uma chave nova e troque nos seus sistemas antes do vencimento, ou ajuste a validade da atual.')
            ->action('Gerenciar chaves', url('/dashboard/api-keys'))
            ->salutation('Atenciosamente, Equipe '.config('app.name'));
    }
}
