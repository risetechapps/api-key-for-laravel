<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Cards;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Payment\PaymentRefundClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use RiseTechApps\ApiKey\Contracts\CardGateway;
use RiseTechApps\ApiKey\Exceptions\PaymentMessageException;
use RiseTechApps\ApiKey\Gateways\GatewayRegistry;
use RiseTechApps\ApiKey\Gateways\StripeGateway;
use RiseTechApps\ApiKey\Jobs\ProcessPlanRenewalJob;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;
use RiseTechApps\ApiKey\Services\AutomaticPaymentService;
use RiseTechApps\ApiKey\Services\MpCustomerService;
use RiseTechApps\ApiKey\Traits\BuildsPaymentPayer;
use RiseTechApps\ApiKey\Traits\SendsIdempotentPayments;

class CardController extends Controller
{
    use BuildsPaymentPayer, SendsIdempotentPayments;

    public function __construct(
        protected readonly MpCustomerService $mpCustomerService,
    ) {}

    public function index(): JsonResponse
    {
        $cards = UserCard::where('authentication_id', auth()->id())->latest()->get();

        // toArray(): o macro jsonSuccess aceita array|JsonResource|null, e uma
        // Collection não é nenhum dos três. Passá-la crua lançava um TypeError —
        // que é Error, não Exception, logo escapava de qualquer catch e devolvia
        // 500. Do lado da SPA o erro era engolido em `catch { savedCards = [] }`,
        // então o sintoma visível era uma lista de cartões sempre vazia.
        return response()->jsonSuccess($cards->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        if ($driver = GatewayRegistry::active()) {
            return $this->storeWithDriver($driver, $request);
        }

        $validated = $request->validate([
            'mp_token' => ['required', 'string'],
            'cpf' => ['required', 'string'],
            'payment_method_id' => ['required', 'string'],
            'holder_name' => ['required', 'string', 'max:255'],
            'brand' => ['required', 'string', 'max:50'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'device_id' => ['nullable', 'string', 'max:255'],
            // Segundo token do cartão, gerado com o CVV junto do primeiro. Cada
            // token serve uma vez só: o primeiro paga a validação, este cria o
            // perfil de Pagamentos Automáticos, que cobra a renovação sem CVV.
            'profile_token' => ['nullable', 'string', 'max:64'],
            'payment_type_id' => ['nullable', 'in:credit_card,debit_card,prepaid_card'],
        ]);

        $user = $this->payerUser();
        $cpf = preg_replace('/\D/', '', $validated['cpf']);

        MercadoPagoConfig::setAccessToken(config('api-key.mercadopago.access_token'));
        $paymentClient = new PaymentClient;

        try {
            // Cobrança de R$5,00 para validar o cartão
            $validationPayload = [
                'transaction_amount' => 5.00,
                'token' => $validated['mp_token'],
                'description' => 'Validação de cartão',
                'installments' => 1,
                'payment_method_id' => $validated['payment_method_id'],
                'external_reference' => $user->getKey().'|card_validation',
                'statement_descriptor' => mb_substr((string) config('app.name') ?: 'Validacao Cartao', 0, 22),
                'payer' => [
                    'email' => strtolower((string) $user->email),
                    'identification' => ['type' => 'CPF', 'number' => $cpf],
                    // Nome no proprio payer, e nao so em additional_info: o
                    // Mercado Pago lista payer.last_name entre os fatores de
                    // aprovacao dos pagamentos.
                    ...$this->payerNames($user->name),
                ],
                'additional_info' => [
                    'payer' => $this->payerAdditionalInfo($user),
                    'items' => [
                        [
                            'id' => 'card_validation',
                            'title' => 'Validação de cartão',
                            'description' => 'Cobrança de validação de cartão de crédito (estornada automaticamente)',
                            'category_id' => 'services',
                            'quantity' => 1,
                            'unit_price' => 5.00,
                        ],
                    ],
                ],
            ];

            if ($notificationUrl = $this->notificationUrl()) {
                $validationPayload['notification_url'] = $notificationUrl;
            }

            $payment = $paymentClient->create($validationPayload, $this->idempotentRequest(
                $validated['idempotency_key'] ?? null,
                ['card_validation', $user->getKey(), $validated['mp_token']],
                $validated['device_id'] ?? null
            ));

            logglyInfo()->withContext([
                'status' => $payment->status,
                'status_detail' => $payment->status_detail,
                'payment_id' => $payment->id,
            ])->log('Card validation payment');

            if ($payment->status !== 'approved') {
                return response()->json([
                    'success' => false,
                    'message' => $this->translateStatusDetail($payment->status_detail ?? ''),
                ], 422);
            }

            // Cria/obtém cliente MP e associa o cartão
            $mpCustomerId = $this->mpCustomerService->getOrCreateCustomer($user);
            $mpCardId = $this->mpCustomerService->attachCard($mpCustomerId, $validated['mp_token']);

            $lastFour = (string) ($payment->card?->last_four_digits ?? '');
            $expiryMonth = str_pad((string) ($payment->card?->expiration_month ?? 1), 2, '0', STR_PAD_LEFT);
            $expiryYear = (string) ($payment->card?->expiration_year ?? date('Y'));

            $existing = UserCard::where('authentication_id', $user->getKey())
                ->where('last_four', $lastFour)
                ->first();

            if ($existing) {
                $existing->update([
                    'mp_customer_id' => $mpCustomerId,
                    'mp_card_id' => $mpCardId ?? $existing->mp_card_id,
                    'is_default' => true,
                    // Cobrança nova, estorno ainda pendente: revalidar o mesmo
                    // cartão cobra de novo, e o estorno anterior não cobre este.
                    'validation_payment_id' => (string) $payment->id,
                    'validation_refunded_at' => null,
                ]);
                $card = $existing;
            } else {
                UserCard::where('authentication_id', $user->getKey())->update(['is_default' => false]);

                $card = UserCard::create([
                    'authentication_id' => $user->getKey(),
                    'holder_name' => $validated['holder_name'],
                    'last_four' => $lastFour,
                    'brand' => $validated['brand'],
                    'expiry_month' => $expiryMonth,
                    'expiry_year' => $expiryYear,
                    'mp_customer_id' => $mpCustomerId,
                    'mp_card_id' => $mpCardId,
                    'is_default' => true,
                    'validation_payment_id' => (string) $payment->id,
                ]);
            }

            // Estorno automático da cobrança de validação.
            //
            // Best-effort de propósito: o cartão já foi validado e associado ao
            // cliente no gateway, e derrubar o cadastro porque o estorno não saiu
            // seria pior para quem está do outro lado. O que não pode é o
            // fracasso sumir — a pendência fica registrada na própria linha e o
            // comando `api-key:retry-validation-refunds` a reprocessa.
            try {
                new PaymentRefundClient()->refund($payment->id, 5.00);

                $card->update(['validation_refunded_at' => now()]);

                logglyInfo()->withContext(['payment_id' => $payment->id])->log('Card validation refunded');
            } catch (\Exception $e) {
                logglyWarning()->withContext([
                    'card_id' => $card->getKey(),
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ])->log('Card validation refund failed');
            }

            // Melhor esforço: sem perfil o cartão continua servindo para pagar
            // com CVV, e a renovação pede o pagamento pelo painel.
            app(AutomaticPaymentService::class)->registerProfile(
                $card,
                $validated['profile_token'] ?? null,
                $validated['payment_type_id'] ?? null,
            );

            $this->retryFailedRenewal($user, $card);

            // jsonBase e não jsonSuccess: o segundo parâmetro de jsonSuccess é a
            // mensagem, não o status, então o 201 nunca chegava a ser aplicado —
            // e o model, como a Collection do index(), não satisfaz o tipo do
            // macro e derrubava a resposta com um TypeError depois de o cartão já
            // ter sido salvo e a cobrança de validação estornada.
            return response()->jsonBase(true, __('api-key::messages.card_saved'), $card->toArray(), 201);

        } catch (MPApiException $e) {
            $body = $e->getApiResponse()?->getContent();
            $detail = $body['status_detail'] ?? $body['message'] ?? '';
            logglyError()->withContext(['body' => $body])->log('MP card validation API error');

            return response()->json([
                'success' => false,
                'message' => $this->translateStatusDetail($detail) ?: __('api-key::messages.error_processing_payment'),
            ], 422);
        } catch (PaymentMessageException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            logglyError()->withContext(['error' => $e->getMessage()])->log('MP card store failed');

            return response()->json(['success' => false, 'message' => __('api-key::messages.error_processing_payment')], 422);
        } catch (\Exception $e) {
            logglyError()->withContext(['error' => $e->getMessage()])->log('Card store error');

            return response()->json(['success' => false, 'message' => __('api-key::messages.error_processing_payment')], 500);
        }
    }

    /**
     * Cartão nos outros gateways: salvo com o que o navegador gerou (token com
     * o CVV, cartão criptografado ou SetupIntent confirmado) e validado pelo
     * próprio gateway, sem a cobrança de R$ 5,00 que o Mercado Pago exige. Já
     * sai pronto para a renovação sem CVV.
     */
    private function storeWithDriver(CardGateway $driver, Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Cartão criptografado do PagBank passa de 300 caracteres.
            'card_token' => ['required', 'string', 'max:4096'],
            'cpf' => [$driver->name() === 'stripe' ? 'nullable' : 'required', 'string', 'max:20'],
            'holder_name' => ['nullable', 'string', 'max:255'],
            'extra' => ['nullable', 'array'],
        ]);

        if (! $driver->configured()) {
            return response()->jsonError(__('api-key::messages.gateway_not_configured'));
        }

        $user = $this->payerUser();

        try {
            $card = $driver->saveCard($user, $validated);
        } catch (PaymentMessageException $e) {
            return response()->jsonError($e->getMessage());
        } catch (\Throwable $e) {
            // Falha de rede ou resposta inesperada do gateway.
            logglyError()->withContext(['gateway' => $driver->name(), 'error' => $e->getMessage()])->log('Card store error');

            return response()->json(['success' => false, 'message' => __('api-key::messages.error_processing_payment')], 500);
        }

        $this->retryFailedRenewal($user, $card);

        return response()->jsonBase(true, __('api-key::messages.card_saved'), $card->toArray(), 201);
    }

    /**
     * Stripe: abre o SetupIntent que o Stripe Elements confirma com o cartão.
     * O id dele volta em `POST /dashboard/cards` (ou no checkout) como
     * `card_token`.
     */
    public function setupIntent(): JsonResponse
    {
        $driver = GatewayRegistry::active();

        if (! $driver instanceof StripeGateway || ! $driver->configured()) {
            return response()->jsonError(__('api-key::messages.gateway_not_configured'));
        }

        try {
            return response()->jsonSuccess($driver->createSetupIntent($this->payerUser()));
        } catch (PaymentMessageException $e) {
            return response()->jsonError($e->getMessage());
        } catch (\RuntimeException $e) {
            logglyError()->withContext(['error' => $e->getMessage()])->log('Stripe setup intent failed');

            return response()->jsonError(__('api-key::messages.error_processing_payment'));
        }
    }

    public function destroy(int $id): JsonResponse
    {
        $card = UserCard::where('authentication_id', auth()->id())->findOrFail($id);

        // Antes da linha local, que é onde estão os ids do gateway. Falha no
        // gateway não segura a remoção: fica no log para conferência.
        $this->mpCustomerService->removeCard($card);

        $card->delete();

        return response()->jsonSuccess();
    }

    /**
     * Ativa a cobrança sem CVV num cartão já salvo, com um token gerado com o
     * CVV. É o caminho de quem salvou o cartão pelo checkout (o Card Payment
     * Brick gera um token só, gasto no pagamento) ou antes dos Pagamentos
     * Automáticos existirem no pacote.
     */
    public function enableAutomaticBilling(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'profile_token' => ['required', 'string', 'max:64'],
            'payment_type_id' => ['nullable', 'in:credit_card,debit_card,prepaid_card'],
        ]);

        $card = UserCard::where('authentication_id', auth()->id())->findOrFail($id);

        if (! AutomaticPaymentService::enabled() || $card->gateway !== 'mercadopago' || ! $card->mp_customer_id) {
            return response()->jsonError(__('api-key::messages.automatic_billing_unavailable'));
        }

        $ready = app(AutomaticPaymentService::class)->registerProfile(
            $card,
            $validated['profile_token'],
            $validated['payment_type_id'] ?? null,
        );

        if (! $ready) {
            return response()->jsonError(__('api-key::messages.automatic_billing_unavailable'));
        }

        // Renovação parada por exigência de CVV volta a ser cobrada agora.
        $this->retryFailedRenewal($this->payerUser(), $card);

        return response()->jsonBase(true, __('api-key::messages.automatic_billing_enabled'), $card->fresh()->toArray());
    }

    /**
     * Renovação recusada esperando nova tentativa: o cartão novo é o que o
     * e-mail pediu, então a cobrança sai agora em vez de na data marcada.
     * Vale também depois da última tentativa, enquanto a assinatura não
     * expirou de vez.
     */
    private function retryFailedRenewal(Authentication $user, UserCard $card): void
    {
        $userPlan = UserPlan::where('authentication_id', $user->getKey())
            ->where('active', true)
            ->whereNull('cancelled_at')
            ->where(fn ($q) => $q->where('renewal_attempts', '>', 0)->orWhereNotNull('renewal_requires_payment'))
            ->latest('end_date')
            ->first();

        // Conta que exige CVV: um cartão novo cai no mesmo erro, a menos que
        // ele tenha saído daqui com perfil de Pagamentos Automáticos, que cobra
        // sem CVV. Sem perfil, a renovação segue pelo painel.
        if (! $userPlan || ($userPlan->renewal_requires_payment === 'cvv_required' && ! $card->automatic_billing)) {
            return;
        }

        $userPlan->update([
            'payment_method' => 'card',
            'renewal_failed_at' => null,
            'renewal_requires_payment' => null,
        ]);

        $queue = config('api-key.billing.queue');
        $job = new ProcessPlanRenewalJob($userPlan->getKey());

        dispatch($queue ? $job->onQueue($queue) : $job);
    }

    private function translateStatusDetail(string $detail): string
    {
        return match ($detail) {
            'cc_rejected_bad_filled_card_number' => 'Número do cartão inválido.',
            'cc_rejected_bad_filled_date' => 'Data de validade inválida.',
            'cc_rejected_bad_filled_other' => 'Dado inválido. Verifique as informações do cartão.',
            'cc_rejected_bad_filled_security_code' => 'Código de segurança inválido.',
            'cc_rejected_blacklist' => 'Cartão não permitido.',
            'cc_rejected_call_for_authorize' => 'Entre em contato com seu banco para autorizar o pagamento.',
            'cc_rejected_card_disabled' => 'Cartão desativado. Entre em contato com seu banco.',
            'cc_rejected_duplicated_payment' => 'Pagamento duplicado detectado.',
            'cc_rejected_high_risk' => 'Pagamento recusado por motivos de segurança.',
            'cc_rejected_insufficient_amount' => 'Saldo insuficiente.',
            'cc_rejected_invalid_installments' => 'Número de parcelas inválido.',
            'cc_rejected_max_attempts' => 'Número máximo de tentativas atingido. Tente outro cartão.',
            default => 'Pagamento recusado. Verifique os dados e tente novamente.',
        };
    }
}
