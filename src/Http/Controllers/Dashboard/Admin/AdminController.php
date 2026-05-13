<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;

class AdminController extends Controller
{
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
