<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_401_when_no_token_is_provided(): void
    {
        $this->getJson(route('api.v1.auth.user'))->assertUnauthorized();
    }

    public function test_returns_the_profile_without_sensitive_fields(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->withToken($user->createToken('phone')->plainTextToken)->getJson(route('api.v1.auth.user'));

        $response->assertOk();
        $response->assertJsonPath('data.id', $user->id);
        $response->assertJsonPath('data.two_factor_enabled', true);
        $response->assertJsonMissingPath('data.password');
        $response->assertJsonMissingPath('data.two_factor_secret');
        $response->assertJsonMissingPath('data.two_factor_recovery_codes');
    }

    public function test_suspended_account_token_returns_403(): void
    {
        $user = User::factory()->suspended()->create();

        $this->withToken($user->createToken('phone')->plainTextToken)
            ->getJson(route('api.v1.auth.user'))
            ->assertForbidden();
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $currentToken = $user->createToken('phone');
        $otherToken = $user->createToken('tablet');

        $this->withToken($currentToken->plainTextToken)->postJson(route('api.v1.auth.logout'))->assertNoContent();

        $this->assertModelMissing($currentToken->accessToken);
        $this->assertModelExists($otherToken->accessToken);
    }

    public function test_resend_verification_returns_202_and_sends_the_email(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->withToken($user->createToken('phone')->plainTextToken)
            ->postJson(route('api.v1.auth.verification.send'))
            ->assertAccepted();

        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
