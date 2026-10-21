<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Team;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rules\Password;
use RiseTechApps\ApiKey\Models\Team\TeamInvitation;
use RiseTechApps\ApiKey\Services\AuthService;
use RiseTechApps\ApiKey\Services\TeamService;

/**
 * Convite para a equipe, fora do login: a tela do link mostra de quem é o
 * convite e, ao aceitar, cria a conta do membro e já devolve o token.
 */
class TeamInvitationController extends Controller
{
    public function __construct(private readonly TeamService $team) {}

    public function show(string $token): JsonResponse
    {
        $invitation = TeamInvitation::findByToken($token);

        if (! $invitation || ! $invitation->isUsable()) {
            return response()->json(['success' => false, 'message' => __('api-key::messages.team_invitation_invalid')], 404);
        }

        return response()->jsonSuccess([
            'name' => $invitation->name,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'account' => mb_convert_case((string) $invitation->account?->name, MB_CASE_TITLE),
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $invitation = TeamInvitation::findByToken($token);

        if (! $invitation) {
            return response()->json(['success' => false, 'message' => __('api-key::messages.team_invitation_invalid')], 404);
        }

        $member = $this->team->accept($invitation, $data['password'], $data['name'] ?? null);

        return response()->jsonSuccess([
            'token' => app(AuthService::class)->issueToken($member, $request)['token'],
            'user' => ['id' => $member->getKey(), 'name' => $member->name, 'email' => $member->email],
        ]);
    }
}
