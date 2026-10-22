<?php

namespace Tests\Feature\Admin;

use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\AuthenticationSettings;
use App\Services\Auth\OAuthProviderRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AuthenticationSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function rolesWithoutSettingsAccess(): array
    {
        return [
            'no role' => [null],
            'member' => ['member'],
            'admin' => ['admin'],
        ];
    }

    #[DataProvider('rolesWithoutSettingsAccess')]
    public function test_users_without_manage_settings_are_forbidden(?string $role): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        if ($role !== null) {
            $user->assignRole($role);
        }

        $this->actingAs($user)->get(route('admin.authentication.edit'))->assertForbidden();
    }

    public function test_guest_accounts_are_forbidden(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actingAs(User::factory()->guest()->create())
            ->put(route('admin.authentication.update'), [
                'registration_enabled' => false,
                'trusted_device_days' => 0,
                'two_factor_required_roles' => [],
            ])
            ->assertForbidden();

        $this->assertTrue(app(AuthenticationSettings::class)->registrationEnabled());
    }

    public function test_super_admin_sees_current_settings_and_providers(): void
    {
        $response = $this->actingAs($this->superAdmin())->get(route('admin.authentication.edit'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/AuthenticationSettings')
            ->where('settings', [
                'registration_enabled' => true,
                'guest_access_enabled' => true,
                'magic_link_enabled' => false,
                'magic_link_expiry_minutes' => 15,
                'trusted_device_days' => 30,
                'two_factor_required_roles' => [],
                'api_token_lifetime_days' => 365,
                'api_token_idle_days' => 60,
            ])
            ->where('signInMethods', ['Email and password'])
            ->where('providers.0.provider', 'google')
            ->where('providers.0.callback_url', route('oauth.callback', 'google')));
    }

    public function test_user_with_manage_settings_permission_can_save_settings(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_settings');
        Setting::store(AuthenticationSettings::GUEST_ACCESS_ENABLED, '0');
        Setting::store(AuthenticationSettings::MAGIC_LINK_ENABLED, '1');
        Setting::store(AuthenticationSettings::MAGIC_LINK_EXPIRY_MINUTES, '7');

        $response = $this->actingAs($manager)->put(route('admin.authentication.update'), [
            'registration_enabled' => false,
            'trusted_device_days' => 7,
            'two_factor_required_roles' => ['admin', 'super_admin'],
        ]);

        $response->assertSessionHas('status', 'Sign-in settings saved.');
        $settings = app(AuthenticationSettings::class);
        $this->assertFalse($settings->registrationEnabled());
        $this->assertFalse($settings->guestAccessEnabled());
        $this->assertTrue($settings->magicLinkEnabled());
        $this->assertSame(7, $settings->magicLinkExpiryMinutes());
        $this->assertSame(7, $settings->trustedDeviceDays());
        $this->assertSame(['admin', 'super_admin'], $settings->twoFactorRequiredRoles());
    }

    public function test_unknown_roles_are_rejected(): void
    {
        $response = $this->actingAs($this->superAdmin())->put(route('admin.authentication.update'), [
            'registration_enabled' => true,
            'trusted_device_days' => 30,
            'two_factor_required_roles' => ['not-a-role'],
        ]);

        $response->assertSessionHasErrors(['two_factor_required_roles.0' => 'The selected two_factor_required_roles.0 is invalid.']);
        $this->assertSame([], app(AuthenticationSettings::class)->twoFactorRequiredRoles());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_manager_can_change_guest_access_and_public_pages_receive_the_saved_state(bool $enabled): void
    {
        $manager = $this->superAdmin();
        Setting::store(AuthenticationSettings::GUEST_ACCESS_ENABLED, $enabled ? '0' : '1');

        $this->actingAs($manager)->put(route('admin.authentication.update'), [
            'registration_enabled' => true,
            'guest_access_enabled' => $enabled,
            'trusted_device_days' => 30,
            'two_factor_required_roles' => [],
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Sign-in settings saved.');

        $this->assertDatabaseHas('settings', ['key' => AuthenticationSettings::GUEST_ACCESS_ENABLED, 'value' => $enabled ? '1' : '0']);
        $this->get(route('admin.authentication.edit'))->assertInertia(fn (Assert $page) => $page->where('settings.guest_access_enabled', $enabled));
        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('canStartGuest', $enabled));
    }

    #[TestWith([false, 'client-id', 'test-secret', []])]
    #[TestWith([true, null, 'test-secret', []])]
    #[TestWith([true, 'client-id', null, []])]
    #[TestWith([true, 'client-id', 'test-secret', ['Google']])]
    public function test_summary_matches_usable_sign_in_providers_even_when_registration_is_closed(bool $enabled, ?string $clientId, ?string $clientSecret, array $expected): void
    {
        $manager = $this->superAdmin();
        app(OAuthProviderRegistry::class)->update('google', [
            'is_enabled' => $enabled, 'client_id' => $clientId, 'client_secret' => $clientSecret,
        ]);
        Setting::store(AuthenticationSettings::REGISTRATION_ENABLED, '0');

        $response = $this->actingAs($manager)->get(route('admin.authentication.edit'))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('signInMethods', ['Email and password', ...$expected])
            ->where('settings.registration_enabled', false));
        $response->assertDontSee('test-secret');
    }

    public function test_invalid_guest_access_value_is_rejected_without_changing_the_setting(): void
    {
        $this->actingAs($this->superAdmin())->put(route('admin.authentication.update'), [
            'registration_enabled' => true,
            'guest_access_enabled' => 'invalid',
            'trusted_device_days' => 30,
            'two_factor_required_roles' => [],
        ])->assertSessionHasErrors('guest_access_enabled');

        $this->assertTrue(app(AuthenticationSettings::class)->guestAccessEnabled());
    }

    private function superAdmin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->assignRole('super_admin');
    }

    public function test_manager_enables_email_links_and_the_summary_and_login_page_reflect_the_saved_setting(): void
    {
        $manager = $this->superAdmin();

        $this->actingAs($manager)->put(route('admin.authentication.update'), [
            'registration_enabled' => true, 'trusted_device_days' => 30, 'two_factor_required_roles' => [],
            'magic_link_enabled' => true, 'magic_link_expiry_minutes' => 12,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('settings', ['key' => AuthenticationSettings::MAGIC_LINK_ENABLED, 'value' => '1']);
        $this->assertDatabaseHas('settings', ['key' => AuthenticationSettings::MAGIC_LINK_EXPIRY_MINUTES, 'value' => '12']);
        $this->get(route('admin.authentication.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('settings.magic_link_enabled', true)->where('settings.magic_link_expiry_minutes', 12)
            ->where('signInMethods', ['Email and password', 'Email sign-in link']));
        $this->app['auth']->guard()->logout();
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('magicLinkEnabled', true));
    }

    #[TestWith(['magic_link_expiry_minutes', 0])]
    #[TestWith(['magic_link_expiry_minutes', 61])]
    #[TestWith(['magic_link_expiry_minutes', 'invalid'])]
    #[TestWith(['magic_link_enabled', 'invalid'])]
    public function test_invalid_magic_link_controls_are_rejected(string $field, mixed $value): void
    {
        $manager = $this->superAdmin();

        $this->actingAs($manager)->put(route('admin.authentication.update'), [
            'registration_enabled' => true, 'trusted_device_days' => 30, 'two_factor_required_roles' => [],
            $field => $value,
        ])->assertSessionHasErrors($field);

        $this->assertFalse(app(AuthenticationSettings::class)->magicLinkEnabled());
        $this->assertSame(15, app(AuthenticationSettings::class)->magicLinkExpiryMinutes());
    }

    public function test_user_without_settings_permission_cannot_enable_magic_links(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actingAs(User::factory()->create())->put(route('admin.authentication.update'), [
            'magic_link_enabled' => true,
        ])->assertForbidden();

        $this->assertFalse(app(AuthenticationSettings::class)->magicLinkEnabled());
    }
}
