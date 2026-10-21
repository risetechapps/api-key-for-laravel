<?php

namespace RiseTechApps\ApiKey\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

// Enviada diretamente (não via listener), então é enfileirada aqui para não
// bloquear a requisição de "esqueci minha senha" no envio do e-mail.
class ResetPasswordNotification extends BaseResetPassword implements ShouldQueue
{
    use Queueable;

    public function __construct($token)
    {
        parent::__construct($token);

        // Mesma conexão do Horizon (por padrão redis); sem isto a notificação
        // enfileiraria no QUEUE_CONNECTION default (database) e nunca sairia.
        $this->connection = config('api-key.queue.connection');
        $this->queue = config('api-key.queue.name');
    }

    #[\Override]
    public function toMail(mixed $notifiable): MailMessage
    {
        $url = $this->resetUrl($notifiable);

        return (new MailMessage)
            ->subject('Redefinição de senha')
            ->greeting("Olá, {$notifiable->name}!")
            ->line('Recebemos uma solicitação para redefinir a senha da sua conta.')
            ->action('Redefinir senha', $url)
            ->line("Este link expirará em {$this->expireTime()} minutos.")
            ->line('Se você não solicitou a redefinição de senha, nenhuma ação é necessária.')
            ->salutation('Atenciosamente, Equipe '.config('app.name'));
    }

    private function expireTime(): int
    {
        return config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
    }
}
