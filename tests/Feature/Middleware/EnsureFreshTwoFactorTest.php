<?php

namespace Tests\Feature\Middleware;

use App\Models\User;
use App\Services\Security\FreshTwoFactor;
use App\Services\Security\Security;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Covers the `security.confirm` route middleware and {@see Security::requireFreshTwoFactor()}.
 */
class EnsureFreshTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'security.confirm:2fa,10'])->post('/_test/payout', fn () => 'paid');
        Route::middleware(['web', 'auth', 'security.confirm:2fa,10,password'])->post('/_test/bank-details', fn () => 'saved');
        Route::middleware(['web', 'auth'])->post('/_test/helper', function () {
            Security::requireFreshTwoFactor(5);

            return 'done';
        });
    }

    public function test_a_confirmation_inside_the_window_lets_the_request_through(): void
    {
        $this->freezeTime();
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->actingAs($user)
            ->withSession([FreshTwoFactor::SESSION_KEY => now()->subMinutes(9)->getTimestamp()])
            ->post('/_test/payout');

        $response->assertOk()->assertSee('paid');
    }

    public function test_a_confirmation_outside_the_window_is_challenged_and_returns_afterwards(): void
    {
        $this->freezeTime();
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->actingAs($user)
            ->withSession([FreshTwoFactor::SESSION_KEY => now()->subMinutes(11)->getTimestamp()])
            ->from('/wallet')
            ->post('/_test/payout');

        $response->assertRedirect(route('security.confirm'));
        $response->assertSessionHas('url.intended', url('/wallet'));
    }

    public function test_json_callers_get_423_with_the_confirm_url(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->actingAs($user)->postJson('/_test/payout');

        $response->assertStatus(423)->assertJson(['confirm_url' => route('security.confirm')]);
    }

    public function test_accounts_without_two_factor_are_sent_to_set_one_up_by_default(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/_test/payout')->assertRedirect(route('security.confirm'));

        $this->get(route('security.confirm'))->assertRedirect(route('security.show'));
    }

    public function test_the_password_fallback_accepts_a_recent_password_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/_test/bank-details')->assertRedirect(route('password.confirm'));

        $this->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
            ->post('/_test/bank-details')
            ->assertOk()
            ->assertSee('saved');
    }

    public function test_the_password_fallback_still_asks_two_factor_accounts_for_a_code(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
            ->post('/_test/bank-details');

        $response->assertRedirect(route('security.confirm'));
    }

    public function test_the_helper_applies_its_own_window(): void
    {
        $this->freezeTime();
        $user = User::factory()->withTwoFactor()->create();

        $this->actingAs($user)
            ->withSession([FreshTwoFactor::SESSION_KEY => now()->subMinutes(4)->getTimestamp()])
            ->post('/_test/helper')
            ->assertOk();

        $this->withSession([FreshTwoFactor::SESSION_KEY => now()->subMinutes(6)->getTimestamp()])
            ->post('/_test/helper')
            ->assertRedirect(route('security.confirm'));
    }
}
