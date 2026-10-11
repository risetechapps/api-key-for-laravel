<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Billing;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

/**
 * Recibos dos pagamentos da própria conta.
 *
 * HTML pronto para imprimir ou salvar como PDF pelo navegador: evita trazer
 * uma biblioteca de PDF para o pacote só por isto.
 */
class ReceiptController extends Controller
{
    /** Pagamentos com recibo: assinaturas pagas e excedentes quitados. */
    public function index(Request $request): JsonResponse
    {
        $plans = UserPlan::with('plan')
            ->where('authentication_id', $request->user()->getKey())
            ->where(fn ($q) => $q->whereNotNull('payment_id')->orWhereNotNull('overage_paid_at'))
            ->latest('start_date')
            ->get();

        $receipts = [];

        foreach ($plans as $userPlan) {
            if ($userPlan->payment_id) {
                $receipts[] = [
                    'id' => $userPlan->getKey(),
                    'kind' => 'subscription',
                    'description' => $userPlan->plan?->name,
                    'amount' => (float) $userPlan->payment_amount,
                    'paid_at' => $userPlan->start_date?->toIso8601String(),
                    'refunded' => $userPlan->refunded_at !== null,
                ];
            }

            if ($userPlan->overage_paid_at) {
                $receipts[] = [
                    'id' => $userPlan->getKey(),
                    'kind' => 'overage',
                    'description' => __('api-key::messages.overage_description', [
                        'plan' => $userPlan->plan?->name,
                        'requests' => $userPlan->overage_requests,
                    ]),
                    'amount' => (float) $userPlan->overage_amount,
                    'paid_at' => $userPlan->overage_paid_at->toIso8601String(),
                    'refunded' => false,
                ];
            }
        }

        return response()->jsonSuccess($receipts);
    }

    public function show(Request $request, string $id): Response
    {
        $userPlan = UserPlan::with(['plan', 'authentication.address'])
            ->where('authentication_id', $request->user()->getKey())
            ->findOrFail($id);

        $kind = $request->query('kind') === 'overage' ? 'overage' : 'subscription';

        abort_if($kind === 'subscription' && ! $userPlan->payment_id, 404);
        abort_if($kind === 'overage' && ! $userPlan->overage_paid_at, 404);

        $user = $userPlan->authentication;

        $receipt = $kind === 'overage'
            ? [
                'number' => 'EXC-'.strtoupper(substr((string) $userPlan->getKey(), 0, 8)),
                'payment_id' => $userPlan->overage_payment_id,
                'paid_at' => $userPlan->overage_paid_at,
                'description' => __('api-key::messages.overage_description', [
                    'plan' => $userPlan->plan?->name,
                    'requests' => $userPlan->overage_requests,
                ]),
                'period' => [$userPlan->start_date, $userPlan->end_date],
                'gross' => (float) $userPlan->overage_amount,
                'credit' => 0.0,
                'amount' => (float) $userPlan->overage_amount,
            ]
            : [
                'number' => 'ASS-'.strtoupper(substr((string) $userPlan->getKey(), 0, 8)),
                'payment_id' => $userPlan->payment_id,
                'paid_at' => $userPlan->start_date,
                'description' => __('api-key::messages.plan_subscription_description', ['plan' => $userPlan->plan?->name]),
                'period' => [$userPlan->start_date, $userPlan->end_date],
                'gross' => (float) $userPlan->payment_amount + (float) $userPlan->credit_applied,
                'credit' => (float) $userPlan->credit_applied,
                'amount' => (float) $userPlan->payment_amount,
            ];

        return response()->view('api-key::receipt', [
            'receipt' => $receipt,
            'refunded' => $kind === 'subscription' && $userPlan->refunded_at !== null,
            'method' => $userPlan->payment_method === 'pix' ? 'PIX' : 'Cartão',
            'customer' => [
                'name' => $user?->name,
                'email' => $user?->email,
                'document' => $user?->cpf,
            ],
            'issuer' => config('api-key.receipt'),
        ]);
    }
}
