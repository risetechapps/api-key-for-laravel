<?php

namespace RiseTechApps\ApiKey\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RiseTechApps\ApiKey\Models\Plan\Plan;

/**
 * Avisa que a compra em análise foi recusada.
 *
 * Fecha a promessa feita no checkout: "pagamento em análise, você será
 * notificado em breve". Sem este aviso o comprador fica esperando um plano que
 * nunca vai chegar, e a primeira notícia costuma ser a fatura sem a cobrança —
 * ou um ticket de suporte.
 */
class PaymentRejectedNotification extends Notification
{
    public function __construct(
        public readonly Plan $plan,
        public readonly float $amount,
        public readonly ?string $reason = null,
        public readonly bool $isRenewal = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $valor = number_format($this->amount, 2, ',', '.');

        $message = (new MailMessage)
            ->subject($this->isRenewal ? 'Renovação não aprovada' : 'Pagamento não aprovado')
            ->greeting("Olá, {$notifiable->name}!")
            ->line("A análise do seu pagamento de **R$ {$valor}** para o plano **{$this->plan->name}** foi concluída, e ele **não foi aprovado**.");

        // Numa renovação a assinatura existe e continua valendo até o
        // vencimento; dizer que "não chegou a ser ativada" assustaria quem
        // ainda está usando o serviço normalmente.
        $message->line($this->isRenewal
            ? '**Nenhum valor foi cobrado.** Sua assinatura atual continua ativa até o fim do período já pago, mas não será renovada enquanto o pagamento não for concluído.'
            : '**Nenhum valor foi cobrado** e a assinatura não chegou a ser ativada.');

        // O detalhe do gateway só entra quando o pacote sabe traduzi-lo. Repassar
        // o código cru diria menos que nada a quem está lendo.
        if ($this->reason !== null) {
            $message->line($this->reason);
        }

        return $message
            ->action(
                $this->isRenewal ? 'Revisar meio de pagamento' : 'Tentar novamente',
                url($this->isRenewal ? '/dashboard/billing' : '/dashboard/plans')
            )
            ->line($this->isRenewal
                ? 'Revise o cartão cadastrado para não perder o acesso no vencimento.'
                : 'Você pode tentar de novo com o mesmo cartão ou com outro meio de pagamento.')
            ->salutation('Atenciosamente, Equipe '.config('app.name'));
    }
}
