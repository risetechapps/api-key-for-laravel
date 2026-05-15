<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Cards;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RiseTechApps\ApiKey\Models\UserCard\UserCard;

class CardController extends Controller
{
    public function index(): JsonResponse
    {
        $cards = UserCard::where('authentication_id', auth()->id())->latest()->get();
        return response()->jsonSuccess($cards);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'holder_name'  => ['required', 'string', 'max:255'],
            'last_four'    => ['required', 'string', 'size:4'],
            'brand'        => ['required', 'string', 'max:50'],
            'expiry_month' => ['required', 'string', 'size:2'],
            'expiry_year'  => ['required', 'string', 'size:4'],
        ]);

        $user = auth()->user();

        $existing = UserCard::where('authentication_id', $user->getKey())
            ->where('last_four', $validated['last_four'])
            ->first();

        if ($existing) {
            return response()->json(['success' => false, 'message' => __('api-key::messages.card_already_registered')], 422);
        }

        UserCard::where('authentication_id', $user->getKey())->update(['is_default' => false]);

        $card = UserCard::create([
            'authentication_id' => $user->getKey(),
            'holder_name'       => $validated['holder_name'],
            'last_four'         => $validated['last_four'],
            'brand'             => $validated['brand'],
            'expiry_month'      => $validated['expiry_month'],
            'expiry_year'       => $validated['expiry_year'],
            'is_default'        => true,
        ]);

        return response()->jsonSuccess($card, 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $card = UserCard::where('authentication_id', auth()->id())->findOrFail($id);
        $card->delete();
        return response()->jsonSuccess();
    }
}
