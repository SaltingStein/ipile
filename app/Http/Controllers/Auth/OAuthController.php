<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\OAuthProviderRegistry;
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
    /**
     * Send the browser to the provider's consent screen.
     */
    public function redirect(string $provider, OAuthProviderRegistry $providers): SymfonyRedirectResponse
    {
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
    ): RedirectResponse {
        abort_unless($providers->configure($provider), 404);

        $currentUser = $request->user();
        $isLinking = $currentUser !== null && ! $currentUser->is_guest;
        $failureRoute = $isLinking ? 'security.show' : 'login';
        $label = OAuthProviderRegistry::SUPPORTED[$provider];

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (InvalidStateException|GuzzleException) {
            return redirect()->route($failureRoute)->withErrors(['oauth' => __(':provider sign-in was cancelled or failed. Please try again.', ['provider' => $label])]);
        }

        try {
            $user = $socialLogin->resolve($provider, $socialUser, $currentUser);
        } catch (SocialLoginException $exception) {
            return redirect()->route($failureRoute)->withErrors(['oauth' => $exception->getMessage()]);
        }

        if ($isLinking) {
            return redirect()->route('security.show')->with('status', __(':provider account connected.', ['provider' => $label]));
        }

        return $authenticator->login($request, $user, remember: false);
    }
}
