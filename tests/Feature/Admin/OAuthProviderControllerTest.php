<?php

namespace Tests\Feature\Admin;

use App\Models\OAuthProvider;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OAuthProviderControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_google_credentials_enables_the_provider_with_an_encrypted_secret(): void
    {
        $response = $this->actingAs($this->superAdmin())->put(route('admin.oauth-providers.update', 'google'), [
            'is_enabled' => true,
            'client_id' => 'google-client-id',
            'client_secret' => 'google-client-secret',
        ]);

        $response->assertSessionHas('status', 'Google sign-in settings saved.');
        $provider = OAuthProvider::query()->where('provider', 'google')->firstOrFail();
        $this->assertTrue($provider->is_enabled);
        $this->assertSame('google-client-secret', $provider->client_secret);
        $this->assertNotSame('google-client-secret', $provider->getRawOriginal('client_secret'));
    }

    public function test_blank_secret_keeps_the_stored_secret(): void
    {
        OAuthProvider::create(['provider' => 'google', 'is_enabled' => true, 'client_id' => 'old-id', 'client_secret' => 'stored-secret']);

        $this->actingAs($this->superAdmin())->put(route('admin.oauth-providers.update', 'google'), [
            'is_enabled' => true,
            'client_id' => 'new-id',
            'client_secret' => '',
        ]);

        $provider = OAuthProvider::query()->where('provider', 'google')->firstOrFail();
        $this->assertSame('new-id', $provider->client_id);
        $this->assertSame('stored-secret', $provider->client_secret);
    }

    public function test_enabling_without_any_secret_is_rejected(): void
    {
        $response = $this->actingAs($this->superAdmin())->put(route('admin.oauth-providers.update', 'google'), [
            'is_enabled' => true,
            'client_id' => 'google-client-id',
            'client_secret' => '',
        ]);

        $response->assertSessionHasErrors(['client_secret' => 'A client secret is required to enable this provider.']);
        $this->assertDatabaseCount('oauth_providers', 0);
    }

    public function test_settings_page_reports_a_saved_secret_without_revealing_it(): void
    {
        OAuthProvider::create(['provider' => 'google', 'is_enabled' => true, 'client_id' => 'google-client-id', 'client_secret' => 'stored-secret']);

        $response = $this->actingAs($this->superAdmin())->get(route('admin.authentication.edit'));

        $response->assertInertia(fn (Assert $page) => $page->where('providers.0.has_client_secret', true));
        $response->assertDontSee('stored-secret');
    }

    public function test_user_without_manage_settings_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create())
            ->put(route('admin.oauth-providers.update', 'google'), ['is_enabled' => false])
            ->assertForbidden();
    }

    public function test_unsupported_provider_returns_404(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.oauth-providers.update', 'myspace'), ['is_enabled' => false])
            ->assertNotFound();
    }

    private function superAdmin(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->assignRole('super_admin');
    }
}
