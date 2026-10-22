<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_dashboard_shows_the_signed_in_users_numbers(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->createToken('Phone');

        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard')
                ->where('stats.teams', 0)
                ->where('stats.api_tokens', 1)
                ->where('stats.two_factor_enabled', true)
                ->where('stats.member_since', $user->created_at->toDateString())
                ->where('showDeveloperChecklist', false));
    }

    public function test_developer_checklist_only_shows_when_running_locally(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('showDeveloperChecklist', true));
    }
}
