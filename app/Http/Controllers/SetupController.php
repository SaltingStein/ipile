<?php

namespace App\Http\Controllers;

use App\Http\Requests\Setup\SetupRequest;
use App\Services\Setup\SuperAdminSetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * First-run page that creates the super admin. Closed (404) once one exists.
 */
class SetupController extends Controller
{
    /**
     * Show the setup form and make sure a setup key has been issued to the server log.
     */
    public function create(SuperAdminSetup $setup): Response
    {
        abort_unless($setup->isRequired(), 404);

        $setup->ensureKeyIssued();

        return Inertia::render('Auth/Setup');
    }

    /**
     * Create the super admin and sign them in.
     */
    public function store(SetupRequest $request, SuperAdminSetup $setup): RedirectResponse
    {
        $user = $setup->createSuperAdmin($request->safe()->only(['name', 'email', 'password']));

        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->route('admin.index')
            ->with('status', __('Setup complete. You are the super admin.'));
    }
}
