<?php

namespace App\Models;

use Database\Factories\TeamInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * A pending invitation for an email address to join a team with a given role.
 */
class TeamInvitation extends Model
{
    /** @use HasFactory<TeamInvitationFactory> */
    use HasFactory;

    public const LINK_LIFETIME_DAYS = 7;

    protected $fillable = [
        'email',
        'role',
        'invited_by',
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Signed link that lets the invited address join the team, valid for {@see self::LINK_LIFETIME_DAYS} days.
     */
    public function acceptUrl(): string
    {
        return URL::temporarySignedRoute(
            'team-invitations.accept',
            now()->addDays(self::LINK_LIFETIME_DAYS),
            ['invitation' => $this->getKey()],
        );
    }
}
