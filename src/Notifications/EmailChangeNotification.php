<?php

namespace RiseTechApps\ApiKey\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

/**
 * Vai para o endereço NOVO, com o link que conclui a troca.
 *
 * Enviada por rota anônima (Notification::route), porque o endereço ainda não
 * é o da conta.
 */
class EmailChangeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Authentication $user, public readonly string $newEmail)
    {
        $this->connection = config('api-key.queue.connection');
        $this->queue = config('api-key.queue.name');
    }

    /** Instância da classe configurada em `api-key.notifications.email_change`. */
    public static function make(Authentication $user, string $newEmail): self
    {
        $class = config('api-key.notifications.email_change', self::class);

        return new $class($user, $newEmail);
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function confirmationUrl(): string
    {
        return URL::temporarySignedRoute(
            'email.change.confirm',
            Carbon::now()->addMinutes(60),
            ['id' => $this->user->getKey(), 'hash' => sha1(strtolower($this->newEmail))]
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirme seu novo e-mail')
            ->greeting("Olá, {$this->user->name}!")
            ->line('Recebemos um pedido para usar este endereço na sua conta.')
            ->action('Confirmar novo e-mail', $this->confirmationUrl())
            ->line('O link vale por 60 minutos. Até lá, o e-mail antigo continua valendo.')
            ->line('Se não foi você, ignore esta mensagem: nada muda sem a confirmação.')
            ->salutation('Atenciosamente, Equipe '.config('app.name'));
    }
}
