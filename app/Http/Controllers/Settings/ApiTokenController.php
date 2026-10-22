<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Auth\ApiTokens;
use App\Services\Auth\AuthenticationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → API tokens: create, list and revoke the user's Sanctum tokens from the browser.
 * A new token's secret is flashed for one request only, so it is shown once and never again.
 */
class ApiTokenController extends Controller
{
    /**
     * Expiry choices offered on the form, in days. Longer choices than the dashboard lifetime are hidden.
     *
     * @var list<int>
     */
    public const EXPIRY_CHOICES = [7, 30, 90, 365];

    public function index(Request $request, ApiTokens $tokens, AuthenticationSettings $settings): Response
    {
        $lifetimeDays = $settings->apiTokenLifetimeDays();

        return Inertia::render('Settings/ApiTokens', [
            'tokens' => $tokens->summaries($request->user()),
            'newToken' => $request->session()->get('api_token'),
            'lifetimeDays' => $lifetimeDays,
            'idleDays' => $settings->apiTokenIdleDays(),
            'expiryChoices' => array_values(array_filter(self::EXPIRY_CHOICES, fn (int $days) => $lifetimeDays === 0 || $days < $lifetimeDays)),
        ]);
    }

    public function store(Request $request, ApiTokens $tokens): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $token = $tokens->create($request->user(), $validated['name'], $validated['expires_in_days'] ?? null);

        return to_route('api-tokens.index')
            ->with('api_token', $token->plainTextToken)
            ->with('status', __('API token created. Copy it now: it will not be shown again.'));
    }

    public function destroy(Request $request, int $tokenId): RedirectResponse
    {
        $deleted = $request->user()->tokens()->whereKey($tokenId)->delete();

        abort_if($deleted === 0, 404);

        return back()->with('status', __('API token revoked.'));
    }
}
