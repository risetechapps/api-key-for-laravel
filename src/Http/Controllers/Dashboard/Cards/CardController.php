<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Cards;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Payment\PaymentRefundClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;
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
        $validated = $request->validate([
            'mp_token' => ['required', 'string'],
            'cpf' => ['required', 'string'],
            'payment_method_id' => ['required', 'string'],
            'holder_name' => ['required', 'string', 'max:255'],
            'brand' => ['required', 'string', 'max:50'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'device_id' => ['nullable', 'string', 'max:255'],
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
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            logglyError()->withContext(['error' => $e->getMessage()])->log('Card store error');

            return response()->json(['success' => false, 'message' => __('api-key::messages.error_processing_payment')], 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        $card = UserCard::where('authentication_id', auth()->id())->findOrFail($id);
        $card->delete();

        return response()->jsonSuccess();
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
