<?php

namespace Tests\Feature\Auth;

use App\Models\OAuthProvider;
use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\AuthenticationSettings;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegisteredUserControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_page_lists_enabled_oauth_providers(): void
    {
        OAuthProvider::create(['provider' => 'google', 'is_enabled' => true, 'client_id' => 'client-id', 'client_secret' => 'client-secret']);

        $response = $this->get(route('register'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Register')
            ->where('oauthProviders', [['provider' => 'google', 'label' => 'Google']]));
    }

    public function test_register_page_returns_404_when_registration_is_closed(): void
    {
        Setting::store(AuthenticationSettings::REGISTRATION_ENABLED, '0');

        $this->get(route('register'))->assertNotFound();
    }

    public function test_valid_registration_creates_an_account_signs_in_and_sends_verification_email(): void
    {
        Notification::fake();

        $response = $this->post(route('register.store'), [
            'name' => 'Ada Obi',
            'email' => 'ada@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertRedirect(route('dashboard'));
        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->is_guest);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_registering_as_a_guest_upgrades_the_same_account(): void
    {
        Notification::fake();
        $guest = User::factory()->guest()->create(['nickname' => 'Ada']);

        $this->actingAs($guest)->post(route('register.store'), [
            'name' => 'Ada Obi',
            'email' => 'ada@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', [
            'id' => $guest->id,
            'nickname' => 'Ada',
            'email' => 'ada@example.com',
            'is_guest' => false,
        ]);
    }

    public function test_registration_rejects_an_email_that_is_already_taken(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $response = $this->from(route('register'))->post(route('register.store'), [
            'name' => 'Ada Obi',
            'email' => 'ada@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertRedirect(route('register'));
        $response->assertSessionHasErrors(['email' => 'The email has already been taken.']);
        $this->assertGuest();
    }

    public function test_registration_rejects_a_mismatched_password_confirmation(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Ada Obi',
            'email' => 'ada@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'different123',
        ]);

        $response->assertSessionHasErrors(['password' => 'The password field confirmation does not match.']);
        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
    }

    public function test_registration_is_forbidden_when_closed_from_the_dashboard(): void
    {
        Setting::store(AuthenticationSettings::REGISTRATION_ENABLED, '0');

        $response = $this->post(route('register.store'), [
            'name' => 'Ada Obi',
            'email' => 'ada@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
    }

    public function test_registered_user_visiting_register_is_redirected_to_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('register'))
            ->assertRedirect(route('dashboard'));
    }
}
