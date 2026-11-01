<?php

namespace App\Services\Auth;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Forms\RegistrationForm;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Turns an OAuth identity into a local user: sign in a linked account, link to the current user,
 * upgrade a guest, or register a new account.
 *
 * An identity is never attached to an existing account just because the email matches; the owner
 * must sign in first and link it from their security settings.
 */
class SocialLogin
{
    public function __construct(private AuthenticationSettings $settings, private RegistrationForm $registrationForm) {}

    /**
     * Resolve the user that should be signed in for this identity.
     *
     * @param  array{name: string, answers?: array<string, mixed>}|null  $profile
     *
     * @throws SocialLoginException
     */
    public function resolve(string $provider, SocialiteUser $socialUser, ?User $currentUser, ?array $profile = null): User
    {
        $label = OAuthProviderRegistry::SUPPORTED[$provider] ?? ucfirst($provider);

        $linkedAccount = SocialAccount::query()
            ->with('user')
            ->where('provider', $provider)
            ->where('provider_user_id', (string) $socialUser->getId())
            ->first();

        if ($currentUser !== null && ! $currentUser->is_guest) {
            return $this->linkToCurrentUser($provider, $label, $socialUser, $currentUser, $linkedAccount);
        }

        if ($linkedAccount !== null) {
            $linkedAccount->update(['email' => $socialUser->getEmail(), 'avatar_url' => $socialUser->getAvatar()]);

            return $linkedAccount->user;
        }

        $email = $socialUser->getEmail();

        if (blank($email)) {
            throw new SocialLoginException("Your {$label} account did not share an email address.");
        }

        if (User::query()->where('email', $email)->exists()) {
            throw new SocialLoginException(
                "An account with this email already exists. Sign in with your password, then connect {$label} from your security settings."
            );
        }

        if (! $this->settings->registrationEnabled()) {
            throw new SocialLoginException('Registration is currently closed.');
        }

        if ($this->settings->oauthProfileRequired() && $profile === null) {
            throw new OAuthProfileRequired($socialUser);
        }

        return DB::transaction(function () use ($provider, $socialUser, $currentUser, $email, $profile): User {
            $attributes = [
                'name' => $profile['name'] ?? ($socialUser->getName() ?: $socialUser->getNickname()),
                'email' => $email,
                'avatar_url' => $socialUser->getAvatar(),
                'is_guest' => false,
                'status' => 'active',
            ];

            $user = $currentUser?->is_guest ? tap($currentUser)->fill($attributes) : new User($attributes);
            $user->email_verified_at = $this->providerVerifiedEmail($provider, $socialUser) ? now() : null;
            $user->save();

            $this->storeAccount($user, $provider, $socialUser);

            if ($profile !== null) {
                $this->registrationForm->record($user, $profile['answers'] ?? []);
            }

            event(new Registered($user));

            return $user;
        });
    }

    /**
     * Remove a linked provider, refusing when it is the user's only way to sign in.
     *
     * @throws SocialLoginException
     */
    public function unlink(User $user, string $provider): void
    {
        $account = $user->socialAccounts()->where('provider', $provider)->first();

        if ($account === null) {
            return;
        }

        if (! $user->hasPassword() && $user->socialAccounts()->count() === 1) {
            throw new SocialLoginException('Set a password before disconnecting your only sign-in method.');
        }

        $account->delete();
    }

    /**
     * @throws SocialLoginException
     */
    private function linkToCurrentUser(string $provider, string $label, SocialiteUser $socialUser, User $currentUser, ?SocialAccount $linkedAccount): User
    {
        if ($linkedAccount !== null && $linkedAccount->user_id !== $currentUser->id) {
            throw new SocialLoginException("This {$label} account is already connected to another user.");
        }

        $this->storeAccount($currentUser, $provider, $socialUser);

        return $currentUser;
    }

    private function storeAccount(User $user, string $provider, SocialiteUser $socialUser): void
    {
        $user->socialAccounts()->updateOrCreate(['provider' => $provider], [
            'provider_user_id' => (string) $socialUser->getId(),
            'email' => $socialUser->getEmail(),
            'avatar_url' => $socialUser->getAvatar(),
        ]);
    }

    /**
     * Socialite's GitHub driver only returns a primary, verified email with its default user:email scope.
     * Other providers must explicitly confirm verification in their profile response.
     */
    private function providerVerifiedEmail(string $provider, SocialiteUser $socialUser): bool
    {
        if ($provider === 'github') {
            return filled($socialUser->getEmail());
        }

        $raw = method_exists($socialUser, 'getRaw') ? $socialUser->getRaw() : [];

        if ($provider === 'x') {
            return filled($socialUser->getEmail()) && ($raw['confirmed_email'] ?? null) === $socialUser->getEmail();
        }

        return filter_var($raw['email_verified'] ?? $raw['verified_email'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
