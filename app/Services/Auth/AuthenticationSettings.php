<?php

namespace App\Services\Auth;

use App\Models\Setting;

/**
 * Typed access to the authentication options that administrators manage from the dashboard.
 */
class AuthenticationSettings
{
    public const REGISTRATION_ENABLED = 'auth.registration_enabled';

    public const OAUTH_PROFILE_REQUIRED = 'auth.oauth_profile_required';

    public const GUEST_ACCESS_ENABLED = 'auth.guest_access_enabled';

    /** Optional wording of the guest quick-start button, e.g. "Try the demo". Blank uses each landing layout's own. */
    public const GUEST_BUTTON_LABEL = 'auth.guest_button_label';

    /** Optional placeholder of the guest quick-start name field. Blank uses "Pick a nickname". */
    public const GUEST_FIELD_LABEL = 'auth.guest_field_label';

    public const MAX_GUEST_LABEL_LENGTH = 40;

    /** Days a guest account may go unused before `guests:prune` deletes it. Zero (the default) keeps them. */
    public const GUEST_RETENTION_DAYS = 'auth.guest_retention_days';

    public const MIN_GUEST_RETENTION_DAYS = 7;

    public const MAX_GUEST_RETENTION_DAYS = 3650;

    public const MAGIC_LINK_ENABLED = 'auth.magic_link_enabled';

    public const MAGIC_LINK_EXPIRY_MINUTES = 'auth.magic_link_expiry_minutes';

    public const DEFAULT_MAGIC_LINK_MINUTES = 15;

    public const MAX_MAGIC_LINK_MINUTES = 60;

    public const TRUSTED_DEVICE_DAYS = 'auth.trusted_device_days';

    public const TWO_FACTOR_REQUIRED_ROLES = 'auth.two_factor_required_roles';

    public const DEFAULT_TRUSTED_DEVICE_DAYS = 30;

    public const API_TOKEN_LIFETIME_DAYS = 'auth.api_token_lifetime_days';

    public const API_TOKEN_IDLE_DAYS = 'auth.api_token_idle_days';

    public const DEFAULT_API_TOKEN_LIFETIME_DAYS = 365;

    public const DEFAULT_API_TOKEN_IDLE_DAYS = 60;

    /**
     * Whether visitors may create new accounts (registration form, API and OAuth sign-up).
     */
    public function registrationEnabled(): bool
    {
        return Setting::valueOf(self::REGISTRATION_ENABLED, '1') === '1';
    }

    /**
     * Whether visitors may start a guest account without signing up. Off by default; installs that already had guest
     * accounts were switched on by a migration so they keep working as before.
     */
    public function guestAccessEnabled(): bool
    {
        return Setting::valueOf(self::GUEST_ACCESS_ENABLED, '0') === '1';
    }

    public function guestButtonLabel(): ?string
    {
        return Setting::valueOf(self::GUEST_BUTTON_LABEL, '') ?: null;
    }

    public function guestFieldLabel(): ?string
    {
        return Setting::valueOf(self::GUEST_FIELD_LABEL, '') ?: null;
    }

    /**
     * Days a guest account may go unused before it is deleted. Zero keeps guest accounts.
     */
    public function guestRetentionDays(): int
    {
        return (int) Setting::valueOf(self::GUEST_RETENTION_DAYS, '0');
    }

    public function oauthProfileRequired(): bool
    {
        return Setting::valueOf(self::OAUTH_PROFILE_REQUIRED, '0') === '1';
    }

    public function magicLinkEnabled(): bool
    {
        return Setting::valueOf(self::MAGIC_LINK_ENABLED, '0') === '1';
    }

    public function magicLinkExpiryMinutes(): int
    {
        return max(1, min(self::MAX_MAGIC_LINK_MINUTES, (int) Setting::valueOf(self::MAGIC_LINK_EXPIRY_MINUTES, (string) self::DEFAULT_MAGIC_LINK_MINUTES)));
    }

    /**
     * How long a "trust this device" choice skips the two-factor challenge. Zero disables the option.
     */
    public function trustedDeviceDays(): int
    {
        return (int) Setting::valueOf(self::TRUSTED_DEVICE_DAYS, (string) self::DEFAULT_TRUSTED_DEVICE_DAYS);
    }

    /**
     * Roles whose members must set up an authenticator app before using the application.
     *
     * @return list<string>
     */
    public function twoFactorRequiredRoles(): array
    {
        $roles = json_decode(Setting::valueOf(self::TWO_FACTOR_REQUIRED_ROLES, '[]'), true);

        return is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
    }

    /**
     * Maximum age of a new API token in days. Zero means tokens never expire.
     */
    public function apiTokenLifetimeDays(): int
    {
        return (int) Setting::valueOf(self::API_TOKEN_LIFETIME_DAYS, (string) self::DEFAULT_API_TOKEN_LIFETIME_DAYS);
    }

    /**
     * Days an API token may go unused before it stops working. Zero turns the idle timeout off.
     */
    public function apiTokenIdleDays(): int
    {
        return (int) Setting::valueOf(self::API_TOKEN_IDLE_DAYS, (string) self::DEFAULT_API_TOKEN_IDLE_DAYS);
    }

    /**
     * Persist the dashboard-managed authentication options. The API token settings are kept when not submitted.
     *
     * @param  array{registration_enabled: bool, oauth_profile_required?: bool, guest_access_enabled?: bool, guest_button_label?: string|null, guest_field_label?: string|null, guest_retention_days?: int, magic_link_enabled?: bool, magic_link_expiry_minutes?: int, trusted_device_days: int, two_factor_required_roles: list<string>, api_token_lifetime_days?: int, api_token_idle_days?: int}  $settings
     */
    public function update(array $settings): void
    {
        Setting::store(self::REGISTRATION_ENABLED, $settings['registration_enabled'] ? '1' : '0');

        if (array_key_exists('oauth_profile_required', $settings)) {
            Setting::store(self::OAUTH_PROFILE_REQUIRED, $settings['oauth_profile_required'] ? '1' : '0');
        }

        if (array_key_exists('guest_access_enabled', $settings)) {
            Setting::store(self::GUEST_ACCESS_ENABLED, $settings['guest_access_enabled'] ? '1' : '0');
        }

        if (array_key_exists('guest_button_label', $settings)) {
            Setting::store(self::GUEST_BUTTON_LABEL, trim((string) $settings['guest_button_label']));
        }

        if (array_key_exists('guest_field_label', $settings)) {
            Setting::store(self::GUEST_FIELD_LABEL, trim((string) $settings['guest_field_label']));
        }

        if (array_key_exists('guest_retention_days', $settings)) {
            Setting::store(self::GUEST_RETENTION_DAYS, (string) $settings['guest_retention_days']);
        }

        if (array_key_exists('magic_link_enabled', $settings)) {
            Setting::store(self::MAGIC_LINK_ENABLED, $settings['magic_link_enabled'] ? '1' : '0');
        }

        if (array_key_exists('magic_link_expiry_minutes', $settings)) {
            Setting::store(self::MAGIC_LINK_EXPIRY_MINUTES, (string) $settings['magic_link_expiry_minutes']);
        }
        Setting::store(self::TRUSTED_DEVICE_DAYS, (string) $settings['trusted_device_days']);
        Setting::store(self::TWO_FACTOR_REQUIRED_ROLES, json_encode(array_values($settings['two_factor_required_roles'])));

        if (array_key_exists('api_token_lifetime_days', $settings)) {
            Setting::store(self::API_TOKEN_LIFETIME_DAYS, (string) $settings['api_token_lifetime_days']);
        }

        if (array_key_exists('api_token_idle_days', $settings)) {
            Setting::store(self::API_TOKEN_IDLE_DAYS, (string) $settings['api_token_idle_days']);
        }
    }
}
