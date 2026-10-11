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

        if ($this->userPlan->is_trial) {
            $mail->line('Este é o seu **período de teste**, sem cobrança. Se não cancelar até '.optional($this->userPlan->end_date)->format('d/m/Y').', a assinatura é renovada e cobrada normalmente.');
        }

        // O recibo fica no painel, que exige login: o link não expõe nada a
        // quem interceptar o e-mail.
        if ($this->userPlan->payment_id) {
            $valor = number_format((float) $this->userPlan->payment_amount, 2, ',', '.');
            $mail->line("Valor pago: **R$ {$valor}**. O recibo está disponível em Painel → Cobrança.");
        }

        return $mail
            ->line("Limite de requisições: **{$this->plan->request_limit}** por ciclo.")
            ->line('Validade do período atual: '.optional($this->userPlan->end_date)->format('d/m/Y').'.')
            ->action('Acessar painel', url('/dashboard'))
            ->salutation('Atenciosamente, Equipe '.config('app.name'));
    }
}
