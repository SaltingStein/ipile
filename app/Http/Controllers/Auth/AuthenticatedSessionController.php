<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Auth\AuthenticationSettings;
use App\Services\Auth\OAuthProviderRegistry;
use App\Services\Auth\SessionAuthenticator;
use App\Services\Setup\SuperAdminSetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Email/password sign-in and sign-out for the browser.
 */
class AuthenticatedSessionController extends Controller
{
    /**
     * Show the login form.
     */
    public function create(AuthenticationSettings $settings, OAuthProviderRegistry $providers, SuperAdminSetup $setup): Response
    {
        return Inertia::render('Auth/Login', [
            'canRegister' => $settings->registrationEnabled(),
            'magicLinkEnabled' => $settings->magicLinkEnabled(),
            'oauthProviders' => $providers->enabled(),
            'setupUrl' => $setup->isRequired() ? route('setup.create') : null,
        ]);
    }

    /**
     * Check the credentials, then sign in or continue to the two-factor challenge.
     */
    public function store(LoginRequest $request, SessionAuthenticator $authenticator): RedirectResponse
    {
        $user = $request->validateCredentials();

        return $authenticator->login($request, $user, $request->boolean('remember'));
    }

    /**
     * End the session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
