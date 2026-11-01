<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Auth\AuthenticationSettings;
use App\Services\Auth\OAuthProviderRegistry;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Dashboard screen for authentication options and OAuth provider credentials, so no code or `.env` change is needed.
 */
class AuthenticationSettingsController extends Controller
{
    /**
     * Show the current authentication settings.
     */
    public function edit(AuthenticationSettings $settings, OAuthProviderRegistry $providers): Response
    {
        return Inertia::render('Admin/AuthenticationSettings', [
            'settings' => [
                'registration_enabled' => $settings->registrationEnabled(),
                'oauth_profile_required' => $settings->oauthProfileRequired(),
                'guest_access_enabled' => $settings->guestAccessEnabled(),
                'guest_button_label' => $settings->guestButtonLabel() ?? '',
                'guest_field_label' => $settings->guestFieldLabel() ?? '',
                'guest_retention_days' => $settings->guestRetentionDays(),
                'magic_link_enabled' => $settings->magicLinkEnabled(),
                'magic_link_expiry_minutes' => $settings->magicLinkExpiryMinutes(),
                'trusted_device_days' => $settings->trustedDeviceDays(),
                'two_factor_required_roles' => $settings->twoFactorRequiredRoles(),
                'api_token_lifetime_days' => $settings->apiTokenLifetimeDays(),
                'api_token_idle_days' => $settings->apiTokenIdleDays(),
            ],
            'roles' => Role::query()->orderBy('name')->pluck('name'),
            'providers' => $providers->forDashboard(),
            'signInMethods' => [
                'Email and password',
                ...($settings->magicLinkEnabled() ? ['Email sign-in link'] : []),
                ...$providers->enabled()->pluck('label')->all(),
            ],
        ]);
    }

    /**
     * Save the general authentication options.
     */
    public function update(Request $request, AuthenticationSettings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'registration_enabled' => ['required', 'boolean'],
            'oauth_profile_required' => ['sometimes', 'boolean'],
            'guest_access_enabled' => ['sometimes', 'boolean'],
            'guest_button_label' => ['sometimes', 'nullable', 'string', 'max:'.AuthenticationSettings::MAX_GUEST_LABEL_LENGTH],
            'guest_field_label' => ['sometimes', 'nullable', 'string', 'max:'.AuthenticationSettings::MAX_GUEST_LABEL_LENGTH],
            // 0 keeps guest accounts; otherwise at least a week, so a short value cannot wipe recent guests by mistake.
            'guest_retention_days' => ['sometimes', 'integer', 'min:0', 'max:'.AuthenticationSettings::MAX_GUEST_RETENTION_DAYS,
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ((int) $value !== 0 && (int) $value < AuthenticationSettings::MIN_GUEST_RETENTION_DAYS) {
                        $fail(__('Enter 0 to keep guest accounts, or at least :days days.', ['days' => AuthenticationSettings::MIN_GUEST_RETENTION_DAYS]));
                    }
                }],
            'magic_link_enabled' => ['sometimes', 'boolean'],
            'magic_link_expiry_minutes' => ['sometimes', 'integer', 'min:1', 'max:'.AuthenticationSettings::MAX_MAGIC_LINK_MINUTES],
            'trusted_device_days' => ['required', 'integer', 'min:0', 'max:365'],
            'two_factor_required_roles' => ['present', 'array'],
            'two_factor_required_roles.*' => ['string', Rule::exists(Role::class, 'name')],
            'api_token_lifetime_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'api_token_idle_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
        ]);

        $settings->update([
            'registration_enabled' => (bool) $validated['registration_enabled'],
            ...(array_key_exists('oauth_profile_required', $validated) ? ['oauth_profile_required' => (bool) $validated['oauth_profile_required']] : []),
            ...(array_key_exists('guest_access_enabled', $validated) ? ['guest_access_enabled' => (bool) $validated['guest_access_enabled']] : []),
            ...(array_key_exists('magic_link_enabled', $validated) ? ['magic_link_enabled' => (bool) $validated['magic_link_enabled']] : []),
            ...array_intersect_key($validated, array_flip(['guest_button_label', 'guest_field_label'])),
            'trusted_device_days' => (int) $validated['trusted_device_days'],
            'two_factor_required_roles' => array_values(array_unique($validated['two_factor_required_roles'])),
            ...array_map('intval', array_intersect_key($validated, array_flip(['api_token_lifetime_days', 'api_token_idle_days', 'magic_link_expiry_minutes', 'guest_retention_days']))),
        ]);

        return back()->with('status', __('Sign-in settings saved.'));
    }
}
