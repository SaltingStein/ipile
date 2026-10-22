<?php

namespace App\Services\Auth;

use App\Models\Setting;

/**
 * Typed access to the authentication options that administrators manage from the dashboard.
 */
class AuthenticationSettings
{
    public const REGISTRATION_ENABLED = 'auth.registration_enabled';

    public const GUEST_ACCESS_ENABLED = 'auth.guest_access_enabled';

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

    public function guestAccessEnabled(): bool
    {
        return Setting::valueOf(self::GUEST_ACCESS_ENABLED, '1') === '1';
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
     * @param  array{registration_enabled: bool, guest_access_enabled?: bool, magic_link_enabled?: bool, magic_link_expiry_minutes?: int, trusted_device_days: int, two_factor_required_roles: list<string>, api_token_lifetime_days?: int, api_token_idle_days?: int}  $settings
     */
    public function update(array $settings): void
    {
        Setting::store(self::REGISTRATION_ENABLED, $settings['registration_enabled'] ? '1' : '0');

        if (array_key_exists('guest_access_enabled', $settings)) {
            Setting::store(self::GUEST_ACCESS_ENABLED, $settings['guest_access_enabled'] ? '1' : '0');
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
