<?php

namespace Tests\Feature\Auth;

use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers PasswordResetLinkController (request a link) and NewPasswordController (choose a new password).
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_STATUS = 'If an account exists for that email, we have sent a password reset link.';

    public function test_forgot_password_page_renders(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/ForgotPassword'));
    }

    public function test_reset_link_is_emailed_to_a_registered_address(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $response = $this->post(route('password.email'), ['email' => $user->email]);

        $response->assertSessionHas('status', self::GENERIC_STATUS);
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_unknown_address_gets_the_same_response_and_no_email(): void
    {
        Notification::fake();

        $response = $this->post(route('password.email'), ['email' => 'nobody@example.com']);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status', self::GENERIC_STATUS);
        Notification::assertNothingSent();
    }

    public function test_reset_page_renders_with_the_token_and_email(): void
    {
        $response = $this->get(route('password.reset', ['token' => 'reset-token', 'email' => 'ada@example.com']));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Auth/ResetPassword')
            ->where('token', 'reset-token')
            ->where('email', 'ada@example.com'));
    }

    public function test_valid_token_sets_the_new_password_and_signs_out_everywhere(): void
    {
        $user = User::factory()->create();
        $user->createToken('phone');
        TrustedDevice::factory()->for($user)->create();
        $token = Password::createToken($user);

        $response = $this->post(route('password.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-secret123',
            'password_confirmation' => 'new-secret123',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status', 'Your password has been reset.');
        $this->assertTrue(Hash::check('new-secret123', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('trusted_devices', 0);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('password.store'), [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'new-secret123',
            'password_confirmation' => 'new-secret123',
        ]);

        $response->assertSessionHasErrors(['email' => 'This password reset token is invalid.']);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
