<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetControllerTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_MESSAGE = 'If an account exists for that email, we have sent a password reset link.';

    public function test_forgot_password_emails_a_registered_user(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->postJson(route('api.v1.auth.forgot-password'), ['email' => $user->email])
            ->assertOk()
            ->assertExactJson(['message' => self::GENERIC_MESSAGE]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_does_not_reveal_unknown_emails(): void
    {
        Notification::fake();

        $this->postJson(route('api.v1.auth.forgot-password'), ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertExactJson(['message' => self::GENERIC_MESSAGE]);

        Notification::assertNothingSent();
    }

    public function test_reset_password_with_a_valid_token_revokes_api_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('phone');

        $response = $this->postJson(route('api.v1.auth.reset-password'), [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'new-secret123',
            'password_confirmation' => 'new-secret123',
        ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('new-secret123', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_reset_password_with_an_invalid_token_returns_422(): void
    {
        $user = User::factory()->create();

        $this->postJson(route('api.v1.auth.reset-password'), [
            'token' => 'invalid',
            'email' => $user->email,
            'password' => 'new-secret123',
            'password_confirmation' => 'new-secret123',
        ])->assertJsonValidationErrors(['email' => 'This password reset token is invalid.']);
    }
}
