<?php

namespace RiseTechApps\ApiKey\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends BaseResetPassword
{
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
            ->salutation('Atenciosamente, Equipe ' . config('app.name'));
    }

    private function expireTime(): int
    {
        return config('auth.passwords.' . config('auth.defaults.passwords') . '.expire', 60);
    }
}
