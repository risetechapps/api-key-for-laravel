<?php

namespace RiseTechApps\ApiKey\Contracts;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RiseTechApps\ApiKey\Exceptions\PaymentMessageException;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;

/**
 * Gateway de pagamento com cartão salvo e PIX.
 *
 * Todo pagamento que um driver devolve vem no formato que o resto do pacote
 * já trata (`id`, `status`, `status_detail`, `external_reference`,
 * `transaction_amount`, `payment_method_id`), e `status` é um destes:
 * `approved`, `in_process`, `pending` (PIX esperando), `rejected` ou
 * `refunded`. É isso que deixa renovação, excedente, webhook, reconciliação e
 * estorno iguais para todos os gateways.
 *
 * O Mercado Pago fica fora desta interface: ele é o gateway original do
 * pacote, com Bricks, Pagamentos Automáticos e o fluxo de cobrança próprio.
 */
interface CardGateway
{
    /** Nome usado na config, na coluna `user_cards.gateway` e no painel. */
    public function name(): string;

    /** Se as credenciais necessárias estão preenchidas. */
    public function configured(): bool;

    /** @return array{ok: bool, message: string} */
    public function testConnection(): array;

    /**
     * Começo dos ids de pagamento deste gateway como o pacote os grava. É o
     * que diz ao estorno, à reconciliação e ao webhook quem consultar.
     */
    public function paymentIdPrefix(): string;

    /** Se o id de pagamento gravado no pacote é deste gateway. */
    public function ownsPaymentId(?string $id): bool;

    /** O que o navegador precisa para tokenizar o cartão (nunca segredos). */
    public function publicConfig(): array;

    /**
     * Salva o cartão a partir do que o navegador gerou.
     *
     * @param  array{card_token: string, cpf?: string|null, holder_name?: string|null, extra?: array<string, mixed>}  $input
     *
     * @throws PaymentMessageException Cartão recusado, com mensagem para o titular.
     * @throws \RuntimeException Falha técnica do gateway (o texto nunca vai para a tela).
     */
    public function saveCard(Authentication $user, array $input): UserCard;

    public function deleteCard(UserCard $card): bool;

    /**
     * Cobra o cartão salvo. `$customerInitiated` é a compra feita pelo
     * titular; sem ele, é cobrança do vendedor (renovação, excedente).
     *
     * @param  array<string, mixed>  $input  O que veio do navegador na compra (ex.: o CVV da primeira cobrança).
     *
     * @throws \RuntimeException Sem resposta utilizável do gateway.
     */
    public function chargeCard(UserCard $card, float $amount, string $reference, string $description, string $idempotencyKey, bool $customerInitiated = false, array $input = []): object;

    public function pixEnabled(): bool;

    /**
     * Gera o PIX. O objeto devolvido tem `point_of_interaction.transaction_data`
     * com `qr_code`, `qr_code_base64` e `ticket_url`, como o do Mercado Pago.
     *
     * @throws PaymentMessageException Falta dado do cliente (mensagem para a tela).
     * @throws \RuntimeException
     */
    public function createPix(Authentication $user, float $amount, string $description, string $reference, Carbon $expiresAt, string $idempotencyKey, ?string $cpf = null): object;

    /**
     * @throws \RuntimeException Falha do gateway que vale tentar de novo.
     */
    public function findPayment(string $id): ?object;

    /**
     * @return string Id do estorno.
     *
     * @throws \RuntimeException
     */
    public function refund(string $id, ?float $amount = null): string;

    /**
     * Ids de pagamento que uma notificação recebida aponta. O pacote consulta
     * cada um na API antes de agir, então o corpo da notificação nunca decide
     * nada sozinho.
     *
     * @return list<string>
     *
     * @throws AuthenticationException Assinatura ou credencial do webhook inválida.
     * @throws \RuntimeException Consulta da notificação falhou; vale pedir reentrega.
     */
    public function webhookPaymentIds(Request $request): array;
}
