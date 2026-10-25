<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\OAuthController;
use App\Models\OAuthProvider;
use App\Models\User;
use App\Services\Auth\OAuthProviderRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OAuthConnectionTest extends TestCase
{
    use RefreshDatabase;

    public static function providers(): array
    {
        return array_map(fn (string $provider): array => [$provider], array_keys(OAuthProviderRegistry::SUPPORTED));
    }

    #[DataProvider('providers')]
    public function test_manager_can_check_a_disabled_provider_without_connecting_an_account(string $provider): void
    {
        $manager = $this->manager();
        OAuthProvider::create(['provider' => $provider, 'is_enabled' => false, 'client_id' => 'client', 'client_secret' => 'secret']);
        Socialite::fake($provider, (new SocialiteUser)->map(['id' => 'identity', 'email' => 'person@example.com']));

        $this->actingAs($manager)->post(route('admin.oauth-providers.test', $provider))
            ->assertRedirect("https://socialite.fake/{$provider}/authorize")
            ->assertSessionHas(OAuthController::CONNECTION_TEST);
        $this->get(route('oauth.callback', $provider))->assertRedirect(route('admin.authentication.edit'))
            ->assertSessionHas('status', OAuthProviderRegistry::SUPPORTED[$provider].' connection verified. No account was connected or created.')
            ->assertSessionMissing(OAuthController::CONNECTION_TEST);

        $this->assertAuthenticatedAs($manager);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('social_accounts', 0);
        $this->assertDatabaseHas('oauth_providers', ['provider' => $provider, 'is_enabled' => false]);
        $this->get(route('oauth.redirect', $provider))->assertNotFound();
    }

    public function test_inertia_starts_an_external_consent_visit(): void
    {
        OAuthProvider::create(['provider' => 'google', 'is_enabled' => false, 'client_id' => 'client', 'client_secret' => 'secret']);
        Socialite::fake('google');

        $this->actingAs($this->manager())->withHeader('X-Inertia', 'true')
            ->post(route('admin.oauth-providers.test', 'google'))
            ->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://socialite.fake/google/authorize');
    }

    public function test_connection_test_needs_permission_and_saved_credentials(): void
    {
        $this->post(route('admin.oauth-providers.test', 'google'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->post(route('admin.oauth-providers.test', 'google'))->assertForbidden();
        $this->actingAs($this->manager())->post(route('admin.oauth-providers.test', 'google'))
            ->assertSessionHasErrors(['oauth' => 'Save a client ID and client secret before testing this provider.']);
        $this->post(route('admin.oauth-providers.test', 'unknown'))->assertNotFound();
    }

    public function test_failed_test_returns_to_settings_without_creating_a_connection(): void
    {
        OAuthProvider::create(['provider' => 'google', 'is_enabled' => false, 'client_id' => 'client', 'client_secret' => 'secret']);
        Socialite::fake('google', fn () => throw new InvalidStateException);

        $this->actingAs($this->manager())->post(route('admin.oauth-providers.test', 'google'));
        $this->get(route('oauth.callback', 'google'))->assertRedirect(route('admin.authentication.edit'))
            ->assertSessionHasErrors(['oauth' => 'Google sign-in was cancelled or failed. Please try again.'])
            ->assertSessionMissing(OAuthController::CONNECTION_TEST);

        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_expired_test_cannot_fall_back_to_normal_account_linking(): void
    {
        OAuthProvider::create(['provider' => 'google', 'is_enabled' => true, 'client_id' => 'client', 'client_secret' => 'secret']);
        Socialite::fake('google');
        $this->actingAs($this->manager())->post(route('admin.oauth-providers.test', 'google'));
        $this->travel(11)->minutes();

        $this->get(route('oauth.callback', 'google'))->assertForbidden();
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_a_test_for_another_provider_is_refused(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->withSession([OAuthController::CONNECTION_TEST => [
            'provider' => 'github', 'user_id' => $manager->id, 'expires_at' => now()->addMinutes(5)->timestamp,
        ]])->get(route('oauth.callback', 'google'))->assertForbidden();

        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_losing_settings_permission_during_consent_prevents_the_test_callback(): void
    {
        $manager = $this->manager();
        OAuthProvider::create(['provider' => 'google', 'is_enabled' => true, 'client_id' => 'client', 'client_secret' => 'secret']);
        Socialite::fake('google');
        $this->actingAs($manager)->post(route('admin.oauth-providers.test', 'google'));
        $manager->revokePermissionTo('manage_settings');

        $this->get(route('oauth.callback', 'google'))->assertForbidden();
        $this->assertDatabaseCount('social_accounts', 0);
    }

    private function manager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_settings');
    }
}
