<?php

namespace App\Services\Account;

use App\Models\FormSubmission;
use App\Models\Payment;
use App\Models\User;

/**
 * Everything the app holds about a person, as one JSON document (NDPR / GDPR
 * right of access and portability). Secrets — password hashes, two-factor
 * secrets, tokens — are never included.
 */
class PersonalDataExport
{
    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        return [
            'exported_at' => now()->toIso8601String(),
            'account' => [
                'name' => $user->name,
                'nickname' => $user->nickname,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'phone_number' => $user->phone_number,
                'avatar_url' => $user->avatar_url,
                'status' => $user->status,
                'two_factor_enabled' => $user->hasEnabledTwoFactorAuthentication(),
                'created_at' => $user->created_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
            'roles' => $user->getRoleNames()->values()->all(),
            'teams' => $user->teams()->get()->map(fn ($team) => ['name' => $team->name, 'role' => $team->pivot->role])->values()->all(),
            'owned_teams' => $user->ownedTeams()->pluck('name')->all(),
            'connected_accounts' => $user->socialAccounts()->get()->map(fn ($account) => ['provider' => $account->provider, 'email' => $account->email])->values()->all(),
            'payments' => $user->payments()->latest('id')->get()->map(fn (Payment $payment) => [
                'reference' => $payment->reference,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'provider' => $payment->provider,
                'status' => $payment->status,
                'created_at' => $payment->created_at?->toIso8601String(),
            ])->values()->all(),
            'form_responses' => FormSubmission::query()->with('form:id,title')->where('user_id', $user->getKey())->latest('id')->get()
                ->map(fn (FormSubmission $submission) => [
                    'form' => $submission->form?->title,
                    'answers' => $submission->data,
                    'submitted_at' => $submission->created_at?->toIso8601String(),
                ])->values()->all(),
        ];
    }
}
