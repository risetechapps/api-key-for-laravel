<?php

namespace RiseTechApps\ApiKey\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

// Enviada diretamente (não via listener), então é enfileirada aqui para não
// bloquear o cadastro no envio do e-mail de verificação.
class EmailVerifyNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        // Mesma conexão do Horizon (por padrão redis); sem isto a notificação
        // enfileiraria no QUEUE_CONNECTION default (database) e nunca sairia.
        $this->connection = config('api-key.queue.connection');
        $this->queue = config('api-key.queue.name');
    }

    #[\Override]
    protected function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            ['id' => $notifiable->getKey(), 'hash' => sha1((string) $notifiable->getEmailForVerification())]
        );
    }

    #[\Override]
    public function toMail($notifiable): MailMessage
    {
        $url = $this->verificationUrl($notifiable);

        return (new MailMessage)
            ->subject('Confirme seu e-mail')
            ->greeting("Olá, {$notifiable->name}!")
            ->line('Obrigado por se cadastrar. Para ativar sua conta, clique no botão abaixo:')
            ->action('Confirmar E-mail', $url)
            ->line('Se você não se cadastrou, ignore este e-mail.')
            ->salutation('Atenciosamente, Equipe do Seu Site');
    }
}
