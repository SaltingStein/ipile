<?php

namespace App\Services\Account;

use App\Models\MagicLink;
use App\Models\SmsMessage;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Audit\Audit;
use App\Services\Auth\BrowserSessionRegistry;
use App\Services\Teams\TeamManager;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a person's account on request (NDPR / GDPR right to erasure).
 *
 * Personal data is removed at once: the account is anonymised, every way of
 * signing in is revoked, and the row is soft deleted. The anonymous row is
 * purged for good after `retention.deleted_records_days`. Payments stay as
 * financial records, attached to the anonymous account.
 */
class AccountDeletion
{
    public function __construct(
        private readonly TeamManager $teams,
        private readonly BrowserSessionRegistry $sessions,
    ) {}

    /**
     * @throws AccountDeletionException when something must be handed over first
     */
    public function ensureCanDelete(User $user): void
    {
        if ($user->hasRole('super_admin') && User::role('super_admin')->count() <= 1) {
            throw new AccountDeletionException(__('You are the only super admin. Make someone else a super admin before deleting your account.'));
        }

        $sharedTeams = $user->ownedTeams()->whereHas('users', fn ($query) => $query->whereKeyNot($user->getKey()))->pluck('name');

        if ($sharedTeams->isNotEmpty()) {
            throw new AccountDeletionException(__('Transfer or delete the teams you own that have other members first: :teams.', ['teams' => $sharedTeams->implode(', ')]));
        }
    }

    /**
     * @throws AccountDeletionException
     */
    public function delete(User $user): void
    {
        $this->ensureCanDelete($user);

        // Teams only this person uses go with the account (forms and files included).
        $user->ownedTeams()->get()->each(fn ($team) => $this->teams->delete($team));

        DB::transaction(function () use ($user): void {
            // Inside the transaction, so a failed deletion leaves no record claiming it happened.
            Audit::record('account.deleted', $user);

            $email = $user->email;

            $user->tokens()->delete();
            $user->socialAccounts()->delete();
            $user->trustedDevices()->delete();
            $user->teams()->detach();
            MagicLink::query()->where('user_id', $user->getKey())->delete();
            SmsMessage::query()->where('user_id', $user->getKey())->delete();

            if ($email !== null) {
                TeamInvitation::query()->where('email', $email)->delete();
            }

            $user->syncRoles([]);
            $user->forceFill([
                'name' => __('Deleted user'),
                'nickname' => null,
                'email' => null,
                'phone_number' => null,
                'avatar_url' => null,
                'password' => null,
                'email_verified_at' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'current_team_id' => null,
                'remember_token' => null,
            ])->save();

            $user->delete();
        });

        $this->sessions->revokeAll($user);
    }
}
