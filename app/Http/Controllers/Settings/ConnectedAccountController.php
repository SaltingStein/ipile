<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\Controller;
use App\Services\Auth\OAuthProviderRegistry;
use App\Services\Auth\SocialLogin;
use App\Services\Auth\SocialLoginException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Disconnect an OAuth provider (connecting goes through {@see OAuthController}).
 */
class ConnectedAccountController extends Controller
{
    /**
     * Unlink the provider unless it is the only way the user can sign in.
     */
    public function destroy(Request $request, string $provider, SocialLogin $socialLogin): RedirectResponse
    {
        abort_unless(array_key_exists($provider, OAuthProviderRegistry::SUPPORTED), 404);

        try {
            $socialLogin->unlink($request->user(), $provider);
        } catch (SocialLoginException $exception) {
            return back()->withErrors(['oauth' => $exception->getMessage()]);
        }

        return back()->with('status', __(':provider account disconnected.', ['provider' => OAuthProviderRegistry::SUPPORTED[$provider]]));
    }
}
