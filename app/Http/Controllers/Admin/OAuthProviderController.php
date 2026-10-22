<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OAuthProvider;
use App\Services\Auth\OAuthProviderRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Save an OAuth provider's client credentials from the dashboard. The secret is stored encrypted
 * and never sent back to the browser.
 */
class OAuthProviderController extends Controller
{
    /**
     * Update the provider credentials and enabled state.
     */
    public function update(Request $request, string $provider, OAuthProviderRegistry $providers): RedirectResponse
    {
        abort_unless(array_key_exists($provider, OAuthProviderRegistry::SUPPORTED), 404);

        $validated = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'client_id' => ['nullable', 'required_if_accepted:is_enabled', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:1000'],
        ]);

        $hasStoredSecret = filled(OAuthProvider::query()->where('provider', $provider)->value('client_secret'));

        if ($request->boolean('is_enabled') && blank($validated['client_secret'] ?? null) && ! $hasStoredSecret) {
            throw ValidationException::withMessages(['client_secret' => __('A client secret is required to enable this provider.')]);
        }

        $providers->update($provider, [
            'is_enabled' => $request->boolean('is_enabled'),
            'client_id' => $validated['client_id'] ?? null,
            'client_secret' => $validated['client_secret'] ?? null,
        ]);

        return back()->with('status', __(':provider sign-in settings saved.', ['provider' => OAuthProviderRegistry::SUPPORTED[$provider]]));
    }
}
