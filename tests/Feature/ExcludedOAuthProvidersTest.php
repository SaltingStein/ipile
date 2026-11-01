<?php

namespace Tests\Feature;

use App\Models\OAuthProvider;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ExcludedOAuthProvidersTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['facebook'])]
    #[TestWith(['apple'])]
    public function test_excluded_providers_are_hidden_and_cannot_sign_in_even_with_saved_credentials(string $provider): void
    {
        OAuthProvider::create(['provider' => $provider, 'is_enabled' => true, 'client_id' => 'saved-id', 'client_secret' => 'saved-secret']);

        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('oauthProviders', []));
        $this->getJson(route('api.v1.auth.oauth.providers'))->assertExactJson(['data' => []]);
        $this->get(route('oauth.redirect', $provider))->assertNotFound();
        $this->get(route('oauth.callback', $provider))->assertNotFound();
        $this->postJson(route('api.v1.auth.oauth.token', $provider), ['access_token' => 'unused-token'])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
    }

    #[TestWith(['facebook'])]
    #[TestWith(['apple'])]
    public function test_excluded_providers_cannot_be_configured_or_tested_in_admin(string $provider): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_settings');

        $this->actingAs($manager)->get(route('admin.authentication.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('providers', fn ($providers): bool => ! collect($providers)->contains('provider', $provider)));
        $this->put(route('admin.oauth-providers.update', $provider), ['is_enabled' => true, 'client_id' => 'id', 'client_secret' => 'secret'])->assertNotFound();
        $this->post(route('admin.oauth-providers.test', $provider))->assertNotFound();
        $this->assertDatabaseCount('oauth_providers', 0);
    }
}
