<?php

namespace Tests\Feature\Notifications;

use App\Listeners\SendNewSignInAlert;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\EventNotification;
use App\Services\Auth\TwoFactorAuthenticator;
use App\Services\Notifications\NotificationCatalog;
use App\Services\Notifications\NotificationSettings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Each place in the app that fires a catalog event.
 */
class NotificationHooksTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_sends_the_welcome_notification(): void
    {
        Notification::fake();

        $this->post(route('register.store'), [
            'name' => 'Ada Obi', 'email' => 'ada@example.com', 'password' => 'secret123', 'password_confirmation' => 'secret123',
        ]);

        $this->assertSentEvent(User::query()->where('email', 'ada@example.com')->firstOrFail(), 'auth.welcome');
    }

    public function test_guest_accounts_are_not_welcomed(): void
    {
        Notification::fake();

        $this->post(route('guest.start'), ['nickname' => 'Ada']);

        Notification::assertNothingSent();
    }

    public function test_changing_the_password_sends_a_security_alert(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->put(route('password.update'), [
            'current_password' => 'password', 'password' => 'new-secret123', 'password_confirmation' => 'new-secret123',
        ])->assertSessionHasNoErrors();

        $this->assertSentEvent($user, 'security.password_changed');
    }

    public function test_resetting_the_password_sends_a_security_alert(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post(route('password.store'), [
            'token' => Password::createToken($user), 'email' => $user->email,
            'password' => 'new-secret123', 'password_confirmation' => 'new-secret123',
        ])->assertSessionHasNoErrors();

        $this->assertSentEvent($user, 'security.password_changed');
    }

    public function test_turning_two_factor_on_and_off_sends_alerts(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $twoFactor = app(TwoFactorAuthenticator::class);

        $twoFactor->beginSetup($user);
        $this->assertTrue($twoFactor->confirmSetup($user, app(Google2FA::class)->getCurrentOtp($user->two_factor_secret)));
        $this->assertSentEvent($user, 'security.two_factor_enabled');

        $twoFactor->disable($user);
        $this->assertSentEvent($user, 'security.two_factor_disabled');

        // Removing a setup that was never confirmed is not a security change.
        Notification::fake();
        $twoFactor->beginSetup($user);
        $twoFactor->disable($user);
        Notification::assertNothingSent();
    }

    public function test_sign_in_from_a_new_browser_sends_an_alert_once(): void
    {
        Notification::fake();
        $user = User::factory()->create(['last_login_at' => now()->subDay()]);

        $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertSentEvent($user, 'security.new_sign_in');
        $response->assertCookie(SendNewSignInAlert::COOKIE_PREFIX.$user->id);

        Notification::fake();
        $this->post(route('logout'));
        $this->withCookie(SendNewSignInAlert::COOKIE_PREFIX.$user->id, '1')
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        Notification::assertNothingSent();
    }

    public function test_the_first_ever_sign_in_does_not_alert(): void
    {
        Notification::fake();
        $user = User::factory()->create(['last_login_at' => null]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertCookie(SendNewSignInAlert::COOKIE_PREFIX.$user->id);

        Notification::assertNothingSent();
    }

    public function test_verification_and_reset_emails_use_the_dashboard_wording(): void
    {
        $user = User::factory()->unverified()->create(['name' => 'Ada']);
        $settings = app(NotificationSettings::class);
        $settings->update(app(NotificationCatalog::class)->find('auth.verify_email'), [
            'channels' => ['mail'], 'mail_subject' => 'Confirm your address, {name}', 'mail_body' => 'Valid for {expires_in} minutes.',
        ]);

        $verify = (new VerifyEmail)->toMail($user);
        $reset = (new ResetPassword('token-123'))->toMail($user);

        $this->assertSame('Confirm your address, Ada', $verify->subject);
        $this->assertSame(['Valid for 60 minutes.'], $verify->introLines);
        $this->assertStringContainsString('/email/verify/', $verify->actionUrl);
        $this->assertSame('Reset your password', $reset->subject);
        $this->assertStringContainsString('token-123', $reset->actionUrl);
        $this->assertNotNull(Setting::valueOf(NotificationSettings::KEY_PREFIX.'auth.verify_email'));
    }

    private function assertSentEvent(User $user, string $event): void
    {
        Notification::assertSentTo($user, EventNotification::class, fn (EventNotification $notification) => $notification->event === $event);
    }
}
