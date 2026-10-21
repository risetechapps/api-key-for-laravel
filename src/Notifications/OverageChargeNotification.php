<?php

namespace RiseTechApps\ApiKey\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RiseTechApps\ApiKey\Models\Plan\Plan;

/**
 * Cobrança do excedente de um período: paga no cartão, ou em aberto para o
 * assinante pagar no painel (`payment_required`, `rejected`).
 */
class OverageChargeNotification extends Notification
{
    public function __construct(
        public readonly ?Plan $plan,
        public readonly string $state,
        public readonly float $amount,
        public readonly int $requests,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $valor = number_format($this->amount, 2, ',', '.');
        $requests = number_format($this->requests, 0, ',', '.');
        $plan = $this->plan->name ?? '';

        $message = (new MailMessage)
            ->subject('Excedente de uso do plano '.$plan)
            ->greeting("Olá, {$notifiable->name}!")
            ->line("No último período, sua conta fez **{$requests} requisições além do limite** do plano **{$plan}**, no total de **R$ {$valor}**.");

        match ($this->state) {
            'paid' => $message->line('O valor foi cobrado no seu cartão salvo.'),
            'rejected' => $message
                ->line('A cobrança no cartão salvo **não foi aprovada**. Entre no painel para pagar com cartão ou PIX, ou para trocar o cartão.')
                ->action('Pagar no painel', url('/dashboard/billing')),
            default => $message
                ->line('Entre no painel para pagar com cartão ou PIX.')
                ->action('Pagar no painel', url('/dashboard/billing')),
        };

        if ($this->state !== 'paid') {
            $message->line('Nunca enviamos cobranças por e-mail: o pagamento é sempre feito dentro do painel.');
        }

        return $message->salutation('Atenciosamente, Equipe '.config('app.name'));
    }
}
