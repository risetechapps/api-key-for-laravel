<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Payment\PaymentRefundClient;
use MercadoPago\MercadoPagoConfig;
use RiseTechApps\ApiKey\Facades\FeatureRegistry;
use RiseTechApps\ApiKey\Http\Resources\Dashboard\Plans\PlansResource;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Plan\Plan;
use RiseTechApps\ApiKey\Models\UserPlan\UserPlan;

class AdminController extends Controller
{
    public function processRefund(string $id): JsonResponse
    {
        $userPlan = UserPlan::whereNotNull('payment_id')->findOrFail($id);

        MercadoPagoConfig::setAccessToken(config('api-key.mercadopago.access_token'));

        try {
            $payment = (new PaymentClient())->get((int) $userPlan->payment_id);
            $client  = new PaymentRefundClient();
            $refund  = $client->refund((int) $userPlan->payment_id, (float) $payment->transaction_amount);

            $userPlan->update(['active' => false]);

            return response()->jsonSuccess([
                'refund_id' => $refund->id,
                'status'    => $refund->status ?? 'processed',
            ]);
        } catch (\Exception $e) {
            report($e);
            return response()->jsonGone(__('api-key::messages.error_processing_refund') . ': ' . $e->getMessage());
        }
    }

    public function refunds(Request $request): JsonResponse
    {
        $subscriptions = UserPlan::with(['plan', 'authentication'])
            ->whereNotNull('payment_id')
            ->latest()
            ->paginate(20);

        return response()->jsonSuccess([
            'data' => $subscriptions->map(fn($up) => [
                'id'         => $up->getKey(),
                'payment_id' => $up->payment_id,
                'active'     => $up->active,
                'start_date' => $up->start_date?->toIso8601String(),
                'end_date'   => $up->end_date?->toIso8601String(),
                'plan'       => [
                    'name'  => $up->plan?->name,
                    'price' => $up->plan?->formatted_price,
                ],
                'payment_amount' => $up->payment_amount
                    ? 'R$ ' . number_format((float) $up->payment_amount, 2, ',', '.')
                    : $up->plan?->formatted_price,
                'user' => [
                    'id'    => $up->authentication?->getKey(),
                    'name'  => $up->authentication?->name,
                    'email' => $up->authentication?->email,
                ],
            ]),
            'total'        => $subscriptions->total(),
            'current_page' => $subscriptions->currentPage(),
            'last_page'    => $subscriptions->lastPage(),
        ]);
    }

    public function plans(): JsonResponse
    {
        $plans = Plan::orderBy('price')->get();
        return response()->jsonSuccess(PlansResource::collection($plans));
    }

    public function features(): JsonResponse
    {
        return response()->jsonSuccess(FeatureRegistry::all());
    }

    public function users(Request $request): JsonResponse
    {
        $search = $request->get('search');

        $users = Authentication::with(['activePlan.plan'])
            ->when($search, fn($q) => $q->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($search) . '%'])
                  ->orWhereRaw('LOWER(email) LIKE ?', ['%' . strtolower($search) . '%']);
            }))
            ->latest()
            ->paginate(20);

        return response()->jsonSuccess([
            'data' => $users->map(fn($u) => [
                'id'         => $u->getKey(),
                'name'       => $u->name,
                'email'      => $u->email,
                'role'       => $u->role ?? 'user',
                'status'     => $u->status,
                'created_at' => $u->created_at?->toIso8601String(),
                'active_plan' => $u->activePlan ? [
                    'name'     => $u->activePlan->plan?->name,
                    'end_date' => $u->activePlan->end_date?->toIso8601String(),
                    'active'   => $u->activePlan->active,
                ] : null,
            ]),
            'total'        => $users->total(),
            'current_page' => $users->currentPage(),
            'last_page'    => $users->lastPage(),
        ]);
    }
}
