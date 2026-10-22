<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\Auth\AuthenticationSettings;
use App\Services\Auth\OAuthProviderRegistry;
use App\Services\Auth\RegisterUser;
use App\Services\Forms\RegistrationForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Browser registration. A signed-in guest is upgraded in place so their history is kept.
 */
class RegisteredUserController extends Controller
{
    /**
     * Show the registration form, or 404 when registration is closed in the dashboard.
     */
    public function create(AuthenticationSettings $settings, OAuthProviderRegistry $providers, RegistrationForm $registrationForm): Response
    {
        abort_unless($settings->registrationEnabled(), 404);

        return Inertia::render('Auth/Register', [
            'oauthProviders' => $providers->enabled(),
            'signupFields' => $registrationForm->fields(),
        ]);
    }

    /**
     * Create the account, sign it in and send the verification email.
     */
    public function store(RegisterRequest $request, RegisterUser $registerUser): RedirectResponse
    {
        $user = $registerUser->register($request->safe()->only(['name', 'email', 'password']), $request->user(), $request->signupAnswers());

        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('dashboard'))
            ->with('status', __('Account created. Check your inbox to verify your email address.'));
    }
}
