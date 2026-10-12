<?php

namespace RiseTechApps\ApiKey\Http\Controllers\Dashboard\Team;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use RiseTechApps\ApiKey\Models\Authentication\Authentication;
use RiseTechApps\ApiKey\Models\Team\TeamInvitation;
use RiseTechApps\ApiKey\Services\TeamService;
use RiseTechApps\ApiKey\Support\Team;

/**
 * Equipe da conta (dono e admin). `auth()->user()` aqui é sempre o dono: o
 * membro admin age como ele (ver EnsureUserIsEnabled); quem de fato agiu vem
 * de Team::actor().
 */
class TeamController extends Controller
{
    public function __construct(private readonly TeamService $team) {}

    public function index(): JsonResponse
    {
        $owner = $this->owner();
        $summary = $this->team->summary($owner);

        return response()->jsonSuccess([
            'owner' => $this->presentMember($owner, Team::OWNER),
            'members' => $summary['members']->map(fn ($m) => $this->presentMember($m, Team::roleOf($m)))->values(),
            'invitations' => $summary['invitations']->map(fn ($i) => [
                'id' => $i->getKey(),
                'name' => $i->name,
                'email' => $i->email,
                'role' => $i->role,
                'expires_at' => $i->expires_at?->toIso8601String(),
                'created_at' => $i->created_at?->toIso8601String(),
            ])->values(),
            'limit' => $summary['limit'],
            'used' => $summary['used'],
            'me' => ['id' => Team::actor()?->getKey(), 'role' => Team::role()],
        ]);
    }

    /** Quem está na equipe (dono incluso), sem e-mail: para atribuir trabalho. */
    public function members(): JsonResponse
    {
        $owner = $this->owner();
        $list = collect([$owner])->concat($owner->members()->orderBy('name')->get())
            ->map(fn (Authentication $m) => ['id' => $m->getKey(), 'name' => $this->displayName($m), 'role' => Team::roleOf($m)]);

        return response()->jsonSuccess($list->values()->all());
    }

    public function invite(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in(Team::MEMBER_ROLES)],
        ]);

        $invitation = $this->team->invite($this->owner(), $this->actor(), $data['name'], $data['email'], $data['role']);

        return response()->jsonSuccess(['id' => $invitation->getKey(), 'message' => __('api-key::messages.team_invitation_sent')]);
    }

    public function resend(string $id): JsonResponse
    {
        $this->team->resend($this->owner(), $this->actor(), $this->invitation($id));

        return response()->jsonSuccess(['message' => __('api-key::messages.team_invitation_sent')]);
    }

    public function cancelInvitation(string $id): JsonResponse
    {
        $this->team->cancel($this->owner(), $this->actor(), $this->invitation($id));

        return response()->jsonSuccess(['message' => __('api-key::messages.team_invitation_cancelled')]);
    }

    public function updateMember(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(Team::MEMBER_ROLES)]]);

        $this->team->updateRole($this->owner(), $this->actor(), $this->member($id), $data['role']);

        return response()->jsonSuccess(['message' => __('api-key::messages.team_member_updated')]);
    }

    public function removeMember(string $id): JsonResponse
    {
        $this->team->remove($this->owner(), $this->actor(), $this->member($id));

        return response()->jsonSuccess(['message' => __('api-key::messages.team_member_removed')]);
    }

    private function presentMember(Authentication $member, ?string $role): array
    {
        return [
            'id' => $member->getKey(),
            'name' => $this->displayName($member),
            // Membro de integração: o e-mail é fictício, o que identifica é o external_id.
            'email' => $member->isExternal() ? null : $member->email,
            'external_id' => $member->external_id,
            'role' => $role,
            'two_factor_enabled' => $member->two_factor_confirmed_at !== null,
            'created_at' => $member->created_at?->toIso8601String(),
        ];
    }

    private function displayName(Authentication $member): string
    {
        return mb_convert_case((string) $member->name, MB_CASE_TITLE);
    }

    private function owner(): Authentication
    {
        $user = auth()->user();
        abort_unless($user instanceof Authentication, 401);

        return $user;
    }

    private function actor(): Authentication
    {
        return Team::actor() ?? $this->owner();
    }

    private function invitation(string $id): TeamInvitation
    {
        return $this->owner()->teamInvitations()->pending()->findOrFail($id);
    }

    private function member(string $id): Authentication
    {
        return $this->owner()->members()->findOrFail($id);
    }
}
