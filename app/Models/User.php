<?php

namespace App\Models;

use App\Services\Auth\DefaultRole;
use App\Services\Auth\TwoFactorAuthenticator;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Shared account model for guest and authenticated users.
 * The schema intentionally keeps onboarding lightweight while still supporting RBAC checks.
 *
 * Two-factor columns are deliberately not mass assignable; they are only written through
 * {@see TwoFactorAuthenticator}.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * New full accounts, and guests who create a full account, get the default role ({@see DefaultRole}).
     */
    protected static function booted(): void
    {
        static::created(function (User $user): void {
            app(DefaultRole::class)->assignTo($user);
        });

        static::updated(function (User $user): void {
            if ($user->wasChanged('is_guest')) {
                app(DefaultRole::class)->assignTo($user);
            }
        });
    }

    protected $fillable = [
        'name',
        'nickname',
        'email',
        'phone_number',
        'avatar_url',
        'password',
        'is_guest',
        'status',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_last_used_window',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'password' => 'hashed',
        'is_guest' => 'boolean',
        'two_factor_secret' => 'encrypted',
        'two_factor_recovery_codes' => 'encrypted:array',
        'two_factor_confirmed_at' => 'datetime',
        'two_factor_last_used_window' => 'integer',
    ];

    /**
     * Return the user profile name in a way that matches the app's lightweight onboarding flow.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->name ?: $this->nickname ?: ($this->email ? explode('@', $this->email)[0] : 'Core User');
    }

    /**
     * Determine whether the user has finished setting up an authenticator app.
     */
    public function hasEnabledTwoFactorAuthentication(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Determine whether the user can sign in with a password (OAuth-only users and guests cannot).
     */
    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    /**
     * Determine whether an administrator has suspended this account.
     */
    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /**
     * Guest accounts have no email address, so they never receive verification mail.
     */
    public function sendEmailVerificationNotification(): void
    {
        if ($this->email === null) {
            return;
        }

        parent::sendEmailVerificationNotification();
    }

    /**
     * Teams the user belongs to as a member (not as owner), with their role on the pivot.
     *
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withPivot('role')->withTimestamps();
    }

    /**
     * @return HasMany<Team, $this>
     */
    public function ownedTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'owner_id');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    /**
     * Every team the user owns or belongs to, alphabetically.
     *
     * @return Collection<int, Team>
     */
    public function allTeams(): Collection
    {
        return $this->ownedTeams()->get()
            ->merge($this->teams()->get())
            ->sortBy(fn (Team $team) => mb_strtolower($team->name))
            ->values();
    }

    /**
     * Make the team the user's current team. Returns false when the user does not belong to it.
     */
    public function switchTeam(Team $team): bool
    {
        if (! $team->hasUser($this)) {
            return false;
        }

        $this->forceFill(['current_team_id' => $team->getKey()])->save();
        $this->setRelation('currentTeam', $team);

        return true;
    }

    /**
     * Phone number for the SMS notification channel.
     */
    public function routeNotificationForSms(): ?string
    {
        return $this->phone_number;
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * @return HasMany<TrustedDevice, $this>
     */
    public function trustedDevices(): HasMany
    {
        return $this->hasMany(TrustedDevice::class);
    }

    /**
     * Security events Aegis recorded about this account.
     *
     * @return HasMany<SecurityEvent, $this>
     */
    public function securityEvents(): HasMany
    {
        return $this->hasMany(SecurityEvent::class);
    }
}
