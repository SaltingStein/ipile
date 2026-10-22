<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Security\FreshTwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class ConfirmablePasswordControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_page_asks_for_password_confirmation_first(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('security.show'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_confirm_page_renders(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('password.confirm'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/ConfirmPassword'));
    }

    public function test_correct_password_confirms_and_continues_to_the_intended_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('security.show'));

        $response = $this->post(route('password.confirm.store'), ['password' => 'password']);

        $response->assertRedirect(route('security.show'));
        $response->assertSessionHas('auth.password_confirmed_at');
    }

    public function test_two_factor_users_are_challenged_with_the_authenticator_code(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->actingAs($user)->get(route('password.confirm'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/ConfirmPassword')
                ->where('requiresAuthenticator', true));
    }

    public function test_correct_authenticator_code_confirms_and_continues_to_the_intended_page(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $this->actingAs($user)->get(route('security.show'));

        $response = $this->post(route('password.confirm.store'), ['code' => (new Google2FA)->getCurrentOtp($user->two_factor_secret)]);

        $response->assertRedirect(route('security.show'));
        $response->assertSessionHas(FreshTwoFactor::SESSION_KEY);
        $response->assertSessionHas('auth.password_confirmed_at');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->post(route('password.confirm.store'), ['password' => 'wrong-password']);

        $response->assertSessionHasErrors(['password' => 'The provided password is incorrect.']);
        $response->assertSessionMissing('auth.password_confirmed_at');
    }

    public function test_account_without_a_password_is_confirmed_without_the_form(): void
    {
        $response = $this->actingAs(User::factory()->withoutPassword()->create())->get(route('password.confirm'));

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('auth.password_confirmed_at');
    }
}
