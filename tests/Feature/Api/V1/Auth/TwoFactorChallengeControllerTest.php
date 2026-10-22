<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorChallengeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_code_exchanges_the_challenge_for_a_token(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $challengeToken = $this->startChallenge($user);

        $response = $this->postJson(route('api.v1.auth.two-factor-challenge'), [
            'challenge_token' => $challengeToken,
            'code' => (new Google2FA)->getCurrentOtp($user->two_factor_secret),
        ]);

        $response->assertOk();
        $response->assertJsonPath('user.id', $user->id);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id, 'name' => 'Ada iPhone']);
    }

    public function test_recovery_code_is_accepted(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $challengeToken = $this->startChallenge($user);

        $response = $this->postJson(route('api.v1.auth.two-factor-challenge'), [
            'challenge_token' => $challengeToken,
            'recovery_code' => 'recovery-code-two',
        ]);

        $response->assertOk();
        $this->assertSame(['recovery-code-one'], $user->fresh()->two_factor_recovery_codes);
    }

    public function test_invalid_code_returns_422_without_a_token(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $challengeToken = $this->startChallenge($user);

        $response = $this->postJson(route('api.v1.auth.two-factor-challenge'), [
            'challenge_token' => $challengeToken,
            'code' => '000000',
        ]);

        $response->assertJsonValidationErrors(['code' => 'The provided two-factor code was invalid.']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_challenge_can_only_be_used_once(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $challengeToken = $this->startChallenge($user);
        $this->postJson(route('api.v1.auth.two-factor-challenge'), ['challenge_token' => $challengeToken, 'recovery_code' => 'recovery-code-one']);

        $response = $this->postJson(route('api.v1.auth.two-factor-challenge'), [
            'challenge_token' => $challengeToken,
            'recovery_code' => 'recovery-code-two',
        ]);

        $response->assertJsonValidationErrors(['challenge_token' => 'The sign-in challenge has expired. Please sign in again.']);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_unknown_challenge_returns_422(): void
    {
        $this->postJson(route('api.v1.auth.two-factor-challenge'), ['challenge_token' => 'made-up', 'code' => '123456'])
            ->assertJsonValidationErrors(['challenge_token' => 'The sign-in challenge has expired. Please sign in again.']);
    }

    private function startChallenge(User $user): string
    {
        return $this->postJson(route('api.v1.auth.login'), [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Ada iPhone',
        ])->json('challenge_token');
    }
}
