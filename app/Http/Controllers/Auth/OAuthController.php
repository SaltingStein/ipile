<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\OAuthProfileRequired;
use App\Services\Auth\OAuthProviderRegistry;
use App\Services\Auth\PendingOAuthProfile;
use App\Services\Auth\SessionAuthenticator;
use App\Services\Auth\SocialLogin;
use App\Services\Auth\SocialLoginException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

/**
 * "Continue with Google" (and other dashboard-enabled providers) for sign-in, sign-up and account linking.
 */
class OAuthController extends Controller
{
    public const CONNECTION_TEST = 'oauth.connection_test';

    /**
     * Send the browser to the provider's consent screen.
     */
    public function redirect(Request $request, string $provider, OAuthProviderRegistry $providers): SymfonyRedirectResponse
    {
        $request->session()->forget([self::CONNECTION_TEST, PendingOAuthProfile::SESSION_KEY]);

        abort_unless($providers->configure($provider), 404);

        return Socialite::driver($provider)->redirect();
    }

    /**
     * Handle the provider's response. Signed-in registered users link the account; everyone else signs in.
     */
    public function callback(
        Request $request,
        string $provider,
        OAuthProviderRegistry $providers,
        SocialLogin $socialLogin,
        SessionAuthenticator $authenticator,
        PendingOAuthProfile $pending,
    ): RedirectResponse {
        $test = $request->session()->pull(self::CONNECTION_TEST);
        $isTesting = is_array($test);

        if ($isTesting) {
            abort_unless(($test['provider'] ?? null) === $provider
                && ($test['user_id'] ?? null) === $request->user()?->getKey()
                && $request->user()?->can('manage_settings')
                && ($test['expires_at'] ?? 0) > now()->timestamp, 403);
        }

        abort_unless($providers->configure($provider, allowDisabled: $isTesting), 404);

        $currentUser = $request->user();
        $isLinking = $currentUser !== null && ! $currentUser->is_guest;
        $failureRoute = $isTesting ? 'admin.authentication.edit' : ($isLinking ? 'security.show' : 'login');
        $label = OAuthProviderRegistry::SUPPORTED[$provider];

        try {
            if ($request->has('error')) {
                throw new InvalidStateException;
            }

            $socialUser = Socialite::driver($provider)->user();
        } catch (InvalidStateException|GuzzleException) {
            return redirect()->route($failureRoute)->withErrors(['oauth' => __(':provider sign-in was cancelled or failed. Please try again.', ['provider' => $label])]);
        }

        if ($isTesting) {
            return redirect()->route('admin.authentication.edit')
                ->with('status', __(':provider connection verified. No account was connected or created.', ['provider' => $label]));
        }

        try {
            $user = $socialLogin->resolve($provider, $socialUser, $currentUser);
        } catch (OAuthProfileRequired $exception) {
            $pending->store($request, $provider, $exception->identity);

            return redirect()->route('oauth.profile.create');
        } catch (SocialLoginException $exception) {
            return redirect()->route($failureRoute)->withErrors(['oauth' => $exception->getMessage()]);
        }

        if ($isLinking) {
            return redirect()->route('security.show')->with('status', __(':provider account connected.', ['provider' => $label]));
        }

        return $authenticator->login($request, $user, remember: false);
    }
}
