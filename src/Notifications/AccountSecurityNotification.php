<?php

namespace RiseTechApps\ApiKey\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso de alteração de segurança: senha trocada, e-mail trocado, 2FA ligado
 * ou desligado, ou bloqueado por tentativas de código.
 *
 * É o que dá ao dono a chance de reagir quando não foi ele. Por isso, na troca
 * de e-mail, vai para o endereço ANTIGO.
 */
class AccountSecurityNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const PASSWORD_CHANGED = 'password_changed';

    public const EMAIL_CHANGED = 'email_changed';

    public const TWO_FACTOR_ENABLED = 'two_factor_enabled';

    public const TWO_FACTOR_DISABLED = 'two_factor_disabled';

    public const TWO_FACTOR_LOCKED = 'two_factor_locked';

    public function __construct(public readonly string $event, public readonly array $context = [])
    {
        $this->connection = config('api-key.queue.connection');
        $this->queue = config('api-key.queue.name');
    }

    /**
     * Instância da classe configurada em `api-key.notifications.account_security`,
     * para o app poder trocar o texto sem mexer no pacote.
     */
    public static function make(string $event, array $context = []): self
    {
        $class = config('api-key.notifications.account_security', self::class);

        return new $class($event, $context);
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        [$subject, $line] = match ($this->event) {
            self::PASSWORD_CHANGED => ['Sua senha foi alterada', 'A senha da sua conta acabou de ser alterada. As outras sessões abertas foram encerradas.'],
            self::EMAIL_CHANGED => ['O e-mail da sua conta foi alterado', 'O e-mail de acesso da sua conta foi trocado para **'.($this->context['new_email'] ?? '').'**. Este endereço não recebe mais avisos da conta.'],
            self::TWO_FACTOR_ENABLED => ['Verificação em duas etapas ativada', 'A verificação em duas etapas foi ativada. A partir de agora o login pede também o código do aplicativo autenticador.'],
            self::TWO_FACTOR_DISABLED => ['Verificação em duas etapas desativada', 'A verificação em duas etapas foi desativada. O login volta a pedir só a senha.'],
            self::TWO_FACTOR_LOCKED => ['Muitas tentativas de código na sua conta', 'Alguém entrou com a sua senha e errou o código da verificação em duas etapas várias vezes. Por segurança, o login da conta ficou bloqueado por alguns minutos.'],
            default => ['Alteração na sua conta', 'Houve uma alteração de segurança na sua conta.'],
        };

        return (new MailMessage)
            ->subject($subject)
            ->greeting('Olá!')
            ->line($line)
            ->line('Se não foi você, redefina sua senha imediatamente e fale com o suporte.')
            ->action('Redefinir senha', url('/forgot-password'))
            ->salutation('Atenciosamente, Equipe '.config('app.name'));
    }
}
