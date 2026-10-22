<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\AuthenticationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IpileFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_onboarding_creates_a_guest_user_and_redirects_to_dashboard(): void
    {
        $response = $this->post(route('guest.start'), [
            'nickname' => 'Ada',
        ]);

        $response->assertRedirect('/dashboard');

        $this->assertDatabaseHas('users', [
            'nickname' => 'Ada',
            'is_guest' => true,
            'status' => 'active',
        ]);
    }

    public function test_guest_onboarding_requires_a_nickname(): void
    {
        $response = $this->from('/')->post(route('guest.start'), [
            'nickname' => '',
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHasErrors('nickname');
        $this->assertGuest();
    }

    public function test_disabled_guest_access_refuses_direct_requests_without_creating_an_account(): void
    {
        Setting::store(AuthenticationSettings::GUEST_ACCESS_ENABLED, '0');

        $this->postJson(route('guest.start'), ['nickname' => 'Ada'])->assertNotFound()
            ->assertJsonPath('message', 'Guest access is disabled. Sign in to continue.');

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page->where('canStartGuest', false));
    }

    public function test_disabling_new_guest_access_keeps_existing_guest_sessions(): void
    {
        $guest = User::factory()->guest()->create();
        Setting::store(AuthenticationSettings::GUEST_ACCESS_ENABLED, '0');

        $this->actingAs($guest)->get(route('dashboard'))->assertOk();

        $this->assertAuthenticatedAs($guest);
    }

    public function test_unauthenticated_visitors_are_redirected_from_dashboard_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_suspended_users_are_denied_access_to_dashboard(): void
    {
        $user = User::factory()->create([
            'nickname' => 'Blocked',
            'status' => 'suspended',
            'is_guest' => false,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertForbidden();
    }

    public function test_landing_page_uses_the_configured_layout_setting(): void
    {
        Setting::query()->create([
            'key' => 'active_landing_layout',
            'value' => 'LayoutBeta',
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Ìpìlẹ̀');
    }

    public function test_about_boilerplate_page_is_visible_by_default(): void
    {
        $response = $this->get(route('about.boilerplate'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->component('AboutBoilerplate'));
    }

    public function test_about_boilerplate_page_can_be_hidden_from_settings(): void
    {
        Setting::store('site.about_page_visible', '0');

        $this->get(route('about.boilerplate'))->assertNotFound();
    }
}
