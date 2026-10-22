<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Notifications\Notify;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Password reset shared by the web and API flows.
 */
class PasswordResetter
{
    public function __construct(private TrustedDeviceManager $trustedDevices) {}

    /**
     * Email a reset link when the address belongs to an account. The outcome is deliberately not
     * returned so callers cannot reveal which addresses are registered.
     */
    public function sendResetLink(string $email): void
    {
        Password::sendResetLink(['email' => $email]);
    }

    /**
     * Reset the password and sign the account out everywhere: other browser sessions (via the password
     * hash check in `auth.session`), "remember me" cookies, API tokens and trusted devices.
     *
     * @param  array{email: string, password: string, token: string}  $credentials
     * @return string The password broker status, e.g. {@see Password::PasswordReset}.
     */
    public function reset(array $credentials): string
    {
        return Password::reset($credentials, function (User $user, string $password): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            $user->tokens()->delete();
            $this->trustedDevices->revokeAll($user);

            event(new PasswordReset($user));

            Notify::event('security.password_changed')->to($user)->with(['link' => route('password.request')])->send();
        });
    }
}
