<?php

namespace RiseTechApps\ApiKey\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

class EmailVerifyNotification extends VerifyEmail
{
    protected function verificationUrl($notifiable)
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            ['id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())]
        );
    }

    public function toMail($notifiable)
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
