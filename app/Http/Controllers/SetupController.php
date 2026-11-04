<?php

namespace App\Http\Controllers;

use App\Http\Requests\Setup\SetupRequest;
use App\Services\Setup\ServerReadiness;
use App\Services\Setup\SuperAdminSetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * First-run page that creates the super admin. Every page redirects here until one exists (RedirectToSetup),
 * and it is closed (404) afterwards.
 */
class SetupController extends Controller
{
    /**
     * Show the setup steps and server checks, and make sure a setup key has been issued to the server log.
     */
    public function create(Request $request, SuperAdminSetup $setup, ServerReadiness $readiness): Response
    {
        abort_unless($setup->isRequired(), 404);

        $setup->ensureKeyIssued();

        return Inertia::render('Auth/Setup', [
            'checks' => $readiness->checks($request),
            'isLocal' => app()->isLocal(),
        ]);
    }

    /**
     * Create the super admin, sign them in and send them to turn on two-factor sign-in first.
     */
    public function store(SetupRequest $request, SuperAdminSetup $setup): RedirectResponse
    {
        $user = $setup->createSuperAdmin($request->safe()->only(['name', 'email', 'password']));

        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->route('security.show')
            ->with('status', __('Setup complete. You are the super admin. This account can change everything, so turn on two-factor sign-in now.'));
    }
}
