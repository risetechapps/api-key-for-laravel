<?php

namespace RiseTechApps\ApiKey\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RiseTechApps\ApiKey\Models\Team\TeamInvitation;
use RiseTechApps\ApiKey\Services\TeamService;
use RiseTechApps\ApiKey\Support\Team;

/** Convite para a equipe de uma conta, com o link para criar a senha. */
class TeamInvitationNotification extends Notification
{
    public function __construct(
        public readonly TeamInvitation $invitation,
        public readonly string $token,
        public readonly string $ownerName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = (string) config('app.name');
        $owner = mb_convert_case($this->ownerName, MB_CASE_TITLE);
        $role = $this->invitation->role === Team::ADMIN ? 'administrador' : 'atendente';

        return (new MailMessage)
            ->subject("{$owner} convidou você para a equipe no {$app}")
            ->greeting('Olá, '.mb_convert_case($this->invitation->name, MB_CASE_TITLE).'!')
            ->line("{$owner} convidou você para entrar na equipe como **{$role}**.")
            ->line('Crie sua senha para acessar o painel. O convite vale por '.TeamService::INVITATION_DAYS.' dias.')
            ->action('Aceitar o convite', url('/invite/'.$this->token))
            ->line('Se você não esperava este convite, ignore este e-mail.')
            ->salutation('Atenciosamente, Equipe '.$app);
    }
}
