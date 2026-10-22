<?php

namespace App\Models;

use App\Services\Teams\TeamRole;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A team owned by one user, with members who each hold a {@see TeamRole}.
 */
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Members other than the owner, with their role on the pivot.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /**
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * Forms the team built in the form builder.
     *
     * @return HasMany<Form, $this>
     */
    public function forms(): HasMany
    {
        return $this->hasMany(Form::class);
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_id === $user->getKey();
    }

    /**
     * Whether the user is the owner or a member.
     */
    public function hasUser(User $user): bool
    {
        return $this->isOwnedBy($user) || $this->users()->whereKey($user->getKey())->exists();
    }

    /**
     * The user's membership role, or null for the owner and for non-members.
     */
    public function roleOf(User $user): ?TeamRole
    {
        // Teams loaded through $user->teams() already carry this user's membership on the pivot.
        $role = $this->relationLoaded('pivot') && (int) $this->pivot->user_id === $user->getKey()
            ? $this->pivot->role
            : $this->users()->whereKey($user->getKey())->first()?->pivot->role;

        return $role === null ? null : TeamRole::tryFrom($role);
    }

    /**
     * Owners and admins may manage settings, members and invitations.
     */
    public function canBeManagedBy(User $user): bool
    {
        return $this->isOwnedBy($user) || (bool) $this->roleOf($user)?->canManageTeam();
    }

    /**
     * Whether someone with this email is already the owner or a member.
     */
    public function hasUserWithEmail(string $email): bool
    {
        return $this->owner()->where('email', $email)->exists()
            || $this->users()->where('email', $email)->exists();
    }
}
