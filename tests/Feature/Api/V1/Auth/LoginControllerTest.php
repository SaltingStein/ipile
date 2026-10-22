<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credentials_return_a_token_for_the_device(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Ada iPhone',
        ]);

        $response->assertOk();
        $response->assertJsonPath('token_type', 'Bearer');
        $response->assertJsonPath('user.id', $user->id);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id, 'name' => 'Ada iPhone']);
        $this->getJson(route('api.v1.auth.user'), ['Authorization' => 'Bearer '.$response->json('token')])
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_wrong_password_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
            'device_name' => 'Ada iPhone',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email' => 'These credentials do not match our records.']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_missing_fields_return_422(): void
    {
        $this->postJson(route('api.v1.auth.login'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password', 'device_name']);
    }

    public function test_suspended_account_returns_422(): void
    {
        $user = User::factory()->suspended()->create();

        $response = $this->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Ada iPhone',
        ]);

        $response->assertJsonValidationErrors(['email' => 'This account has been suspended.']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_two_factor_account_receives_a_challenge_instead_of_a_token(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Ada iPhone',
        ]);

        $response->assertOk();
        $response->assertJsonPath('two_factor_required', true);
        $response->assertJsonMissingPath('token');
        $this->assertNotEmpty($response->json('challenge_token'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
