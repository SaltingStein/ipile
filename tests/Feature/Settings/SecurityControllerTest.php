<?php

namespace Tests\Feature\Settings;

use App\Models\OAuthProvider;
use App\Models\SecurityEvent;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\TwoFactorAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SecurityControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_account_is_sent_to_registration(): void
    {
        $this->actingAs(User::factory()->guest()->create())
            ->get(route('security.show'))
            ->assertRedirect(route('register'));
    }

    public function test_signed_out_visitor_is_sent_to_login(): void
    {
        $this->get(route('security.show'))->assertRedirect(route('login'));
    }

    public function test_enabled_two_factor_shows_recovery_codes_but_no_setup_key(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->confirmedAs($user)->get(route('security.show'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Security')
            ->where('hasPassword', true)
            ->where('twoFactor.enabled', true)
            ->where('twoFactor.pending', false)
            ->where('twoFactor.setupKey', null)
            ->where('twoFactor.recoveryCodes', ['recovery-code-one', 'recovery-code-two']));
    }

    public function test_pending_setup_shows_the_qr_code_and_setup_key(): void
    {
        $user = User::factory()->create();
        app(TwoFactorAuthenticator::class)->beginSetup($user);

        $response = $this->confirmedAs($user)->get(route('security.show'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('twoFactor.pending', true)
            ->where('twoFactor.setupKey', $user->fresh()->two_factor_secret)
            ->where('twoFactor.qrCodeSvg', fn (string $svg) => str_starts_with($svg, '<svg')));
    }

    public function test_connected_accounts_list_enabled_and_linked_providers(): void
    {
        OAuthProvider::create(['provider' => 'google', 'is_enabled' => true, 'client_id' => 'id', 'client_secret' => 'secret']);
        $user = User::factory()->create();
        SocialAccount::factory()->for($user)->create(['email' => 'ada@gmail.com']);

        $response = $this->confirmedAs($user)->get(route('security.show'));

        $response->assertInertia(fn (Assert $page) => $page->where('connectedAccounts', [[
            'provider' => 'google',
            'label' => 'Google',
            'connected' => true,
            'email' => 'ada@gmail.com',
            'canConnect' => true,
        ]]));
    }

    public function test_the_owner_sees_their_recent_security_activity_but_not_other_peoples(): void
    {
        $user = User::factory()->create();
        SecurityEvent::factory()->for($user)->ofType('account.password_changed', 'account')->create([
            'ip_address' => '102.89.4.10',
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0) Safari/604.1',
        ]);
        SecurityEvent::factory()->create();

        $response = $this->confirmedAs($user)->get(route('security.show'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('activity.enabled', true)
            ->has('activity.items', 1)
            ->where('activity.items.0.label', 'Password changed')
            ->where('activity.items.0.device', 'Safari on iOS')
            ->where('activity.items.0.ip_address', '102.89.4.10')
            ->missing('activity.items.0.signals'));
    }

    private function confirmedAs(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }
}
