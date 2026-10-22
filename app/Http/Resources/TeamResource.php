<?php

namespace App\Http\Resources;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A team as seen by the requesting user. Members and invitations are included when loaded;
 * invitations are only shown to the owner and admins.
 *
 * @mixin Team
 */
class TeamResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isOwner = $this->isOwnedBy($user);
        $canManage = $this->canBeManagedBy($user);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'owner' => $this->whenLoaded('owner', fn () => $this->person($this->owner)),
            'members' => $this->whenLoaded('users', fn () => $this->users->map(fn (User $member) => [
                ...$this->person($member),
                'role' => $member->pivot->role,
            ])->values()),
            'invitations' => $this->when($canManage && $this->relationLoaded('invitations'), fn () => $this->invitations
                ->map(fn (TeamInvitation $invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role,
                    'created_at' => $invitation->created_at?->toIso8601String(),
                ])->values()),
            'current_user' => [
                'role' => $isOwner ? 'owner' : $this->roleOf($user)?->value,
                'is_owner' => $isOwner,
                'can_manage' => $canManage,
                'is_current_team' => $user->current_team_id === $this->id,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, name: string, email: ?string}
     */
    private function person(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->display_name,
            'email' => $user->email,
        ];
    }
}
