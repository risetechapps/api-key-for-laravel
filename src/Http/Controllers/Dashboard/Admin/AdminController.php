<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
            $payment = new PaymentClient()->get((int) $userPlan->payment_id);
            $client  = new PaymentRefundClient();
            $refund  = $client->refund((int) $userPlan->payment_id, (float) $payment->transaction_amount);

            $userPlan->update(['active' => false]);

            return response()->jsonSuccess([
                'refund_id' => $refund->id,
                'status'    => $refund->status ?? 'processed',
            ]);
        } catch (\Exception $e) {
            // A correlation code goes to the panel, the detail goes to the log.
            // Concatenating $e->getMessage() into the response rendered whatever
            // the catch happened to swallow straight into the admin screen — a
            // QueryException carries the SQL with its bound values, an
            // MPApiException carries Mercado Pago's internal detail — and from
            // there it travels into screenshots and support tickets. report()
            // already puts the full exception where operators can read it.
            $errorId = strtoupper(Str::random(8));

            Log::error('Refund failed', [
                'error_id'     => $errorId,
                'user_plan_id' => $userPlan->getKey(),
                'payment_id'   => $userPlan->payment_id,
                'admin_id'     => auth()->id(),
                'exception'    => $e->getMessage(),
            ]);

            report($e);

            return response()->jsonGone(
                __('api-key::messages.error_processing_refund', ['id' => $errorId])
            );
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

        // Escaped so a % or _ typed by the operator is matched literally instead
        // of turning into a wildcard. The LOWER(col) LIKE shape is kept because
        // that is what the pg_trgm indexes are built on.
        $term = $search ? '%' . addcslashes(strtolower((string) $search), '%_\\') . '%' : null;

        $users = Authentication::with(['activePlan.plan'])
            ->when($term, fn($q) => $q->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(name) LIKE ?', [$term])
                  ->orWhereRaw('LOWER(email) LIKE ?', [$term]);
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
