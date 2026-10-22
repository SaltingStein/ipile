<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\OAuthProvider;
use App\Models\User;
use App\Services\Auth\OAuthTokenResolver;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery\MockInterface;
use Tests\TestCase;

class OAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_providers_lists_only_enabled_and_configured_providers(): void
    {
        $this->enableGoogle();

        $this->getJson(route('api.v1.auth.oauth.providers'))
            ->assertOk()
            ->assertExactJson(['data' => [['provider' => 'google', 'label' => 'Google']]]);
    }

    public function test_google_access_token_is_exchanged_for_an_api_token(): void
    {
        $this->enableGoogle();
        $this->mock(OAuthTokenResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->once()->with('google', 'google-access-token')->andReturn($this->googleUser('ada@example.com'));
        });

        $response = $this->postJson(route('api.v1.auth.oauth.token', 'google'), [
            'access_token' => 'google-access-token',
            'device_name' => 'Ada Pixel',
        ]);

        $response->assertOk();
        $response->assertJsonPath('user.email', 'ada@example.com');
        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'provider_user_id' => 'google-1']);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id, 'name' => 'Ada Pixel']);
    }

    public function test_token_rejected_by_google_returns_422(): void
    {
        $this->enableGoogle();
        $this->mock(OAuthTokenResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->andThrow(new ClientException('Unauthorized', new Request('GET', 'https://www.googleapis.com'), new Response(401)));
        });

        $this->postJson(route('api.v1.auth.oauth.token', 'google'), ['access_token' => 'bad-token', 'device_name' => 'Ada Pixel'])
            ->assertJsonValidationErrors(['access_token' => 'The access token could not be verified.']);
    }

    public function test_email_owned_by_another_account_returns_422(): void
    {
        $this->enableGoogle();
        User::factory()->create(['email' => 'ada@example.com']);
        $this->mock(OAuthTokenResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->andReturn($this->googleUser('ada@example.com'));
        });

        $response = $this->postJson(route('api.v1.auth.oauth.token', 'google'), ['access_token' => 'token', 'device_name' => 'Ada Pixel']);

        $response->assertJsonValidationErrors(['access_token']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_disabled_provider_returns_404(): void
    {
        $this->postJson(route('api.v1.auth.oauth.token', 'google'), ['access_token' => 'token', 'device_name' => 'Ada Pixel'])
            ->assertNotFound();
    }

    private function enableGoogle(): void
    {
        OAuthProvider::create(['provider' => 'google', 'is_enabled' => true, 'client_id' => 'id', 'client_secret' => 'secret']);
    }

    private function googleUser(string $email): SocialiteUser
    {
        return (new SocialiteUser)
            ->setRaw(['email_verified' => true])
            ->map(['id' => 'google-1', 'name' => 'Ada Obi', 'email' => $email, 'avatar' => null]);
    }
}
