<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AuthenticationSettings;
use App\Services\Auth\OAuthProviderRegistry;
use App\Services\Auth\PendingOAuthProfile;
use App\Services\Auth\SessionAuthenticator;
use App\Services\Auth\SocialLogin;
use App\Services\Auth\SocialLoginException;
use App\Services\Forms\RegistrationForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OAuthProfileController extends Controller
{
    public function create(Request $request, PendingOAuthProfile $pending, RegistrationForm $registrationForm, AuthenticationSettings $settings): Response|RedirectResponse
    {
        $profile = $pending->get($request);

        if ($profile === null || ! $settings->registrationEnabled()) {
            return redirect()->route('login')->withErrors(['oauth' => __('Restart connected sign-in to complete your profile.')]);
        }

        return Inertia::render('Auth/CompleteOAuthProfile', [
            'name' => $profile['identity']->getName() ?? '',
            'email' => $profile['identity']->getEmail(),
            'signupFields' => $registrationForm->fields(),
            'submitUrl' => route('oauth.profile.store'),
        ]);
    }

    public function store(Request $request, PendingOAuthProfile $pending, RegistrationForm $registrationForm, SocialLogin $socialLogin, SessionAuthenticator $authenticator, OAuthProviderRegistry $providers): RedirectResponse
    {
        $profile = $pending->get($request);

        if ($profile === null) {
            return redirect()->route('login')->withErrors(['oauth' => __('Restart connected sign-in to complete your profile.')]);
        }

        abort_unless($providers->configure($profile['provider']), 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            ...$registrationForm->rules(),
        ], [], $registrationForm->attributes());

        try {
            $user = $socialLogin->resolve($profile['provider'], $profile['identity'], $request->user(), $validated);
        } catch (SocialLoginException $exception) {
            return redirect()->route('login')->withErrors(['oauth' => $exception->getMessage()]);
        }

        $request->session()->forget(PendingOAuthProfile::SESSION_KEY);

        return $authenticator->login($request, $user, remember: false);
    }
}
