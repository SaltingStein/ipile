<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\MagicLinkController;
use App\Jobs\SendMagicLink;
use App\Models\MagicLink;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\EventNotification;
use App\Services\Auth\AuthenticationSettings;
use App\Services\Auth\SessionAuthenticator;
use App\Services\Notifications\NotificationCatalog;
use App\Services\Notifications\NotificationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class MagicLinkControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_magic_links_are_off_by_default_on_the_page_and_both_endpoints(): void
    {
        Notification::fake();

        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('magicLinkEnabled', false));
        $this->post(route('magic-link.request'), ['email' => 'ada@example.com'])->assertNotFound();
        $this->get(route('magic-link.verify', 'invalid'))->assertNotFound();

        $this->assertDatabaseCount('magic_links', 0);
        Notification::assertNothingSent();
    }

    public function test_request_sends_a_signed_link_with_a_hashed_token_and_the_configured_expiry(): void
    {
        $this->freezeTime();
        $this->enable();
        Setting::store(AuthenticationSettings::MAGIC_LINK_EXPIRY_MINUTES, '5');
        $user = User::factory()->create(['email' => 'ada@example.com']);
        Notification::fake();

        $this->post(route('magic-link.request'), ['email' => 'ADA@example.com'])->assertRedirect(route('login'))
            ->assertSessionHas('status', MagicLinkController::SENT_MESSAGE);

        Notification::assertSentTo($user, EventNotification::class, fn (EventNotification $notification) => $notification->event === 'auth.magic_link');
        $notification = Notification::sent($user, EventNotification::class)->first();
        $url = $notification->data['link'];
        $token = basename(parse_url($url, PHP_URL_PATH));
        $link = MagicLink::query()->sole();
        $this->assertTrue(URL::hasValidSignature(Request::create($url)));
        $this->assertSame(hash('sha256', $token), $link->token);
        $this->assertNotSame($token, $link->getRawOriginal('token'));
        $this->assertSame(now()->addMinutes(5)->timestamp, $link->expires_at->timestamp);
        $this->assertSame(5, $notification->data['expires_in']);
        $this->assertArrayNotHasKey('token', $link->toArray());
        $this->assertArrayNotHasKey('email', $link->toArray());
    }

    public function test_unknown_suspended_and_guest_emails_receive_the_same_public_response_without_mail(): void
    {
        $this->enable();
        User::factory()->suspended()->create(['email' => 'suspended@example.com']);
        User::factory()->guest()->create(['email' => 'guest@example.com']);
        Notification::fake();

        foreach (['unknown@example.com', 'suspended@example.com', 'guest@example.com'] as $email) {
            $this->post(route('magic-link.request'), ['email' => $email])->assertRedirect(route('login'))
                ->assertSessionHas('status', MagicLinkController::SENT_MESSAGE)->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('magic_links', 0);
        Notification::assertNothingSent();
    }

    public function test_valid_link_signs_in_a_passwordless_account_even_when_registration_is_closed_and_cannot_be_replayed(): void
    {
        $this->enable();
        Setting::store(AuthenticationSettings::REGISTRATION_ENABLED, '0');
        $user = User::factory()->withoutPassword()->create();
        [$link, $url] = $this->link($user);
        Notification::fake();

        $this->get($url)->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($link->fresh()->used_at);
        Auth::logout();
        $this->get($url)->assertRedirect(route('login'))->assertSessionHasErrors('magic_link');
        $this->assertGuest();
        Notification::assertNotSentTo($user, EventNotification::class, fn (EventNotification $notification) => $notification->event === 'auth.magic_link');
    }

    public function test_tampered_url_is_refused_without_consuming_the_token(): void
    {
        $this->enable();
        [$link, $url] = $this->link(User::factory()->create());

        $this->get($url.'&expires=9999999999')->assertRedirect(route('login'))
            ->assertSessionHasErrors(['magic_link' => MagicLinkController::INVALID_MESSAGE]);

        $this->assertNull($link->fresh()->used_at);
        $this->assertGuest();
    }

    public function test_unknown_token_with_a_valid_signature_is_refused(): void
    {
        $this->enable();
        $url = URL::temporarySignedRoute('magic-link.verify', now()->addMinutes(15), ['token' => Str::random(64)]);

        $this->get($url)->assertRedirect(route('login'))->assertSessionHasErrors('magic_link');

        $this->assertGuest();
    }

    #[TestWith(['expired'])]
    #[TestWith(['used'])]
    public function test_expired_or_used_database_tokens_are_refused_even_with_a_current_url_signature(string $state): void
    {
        $this->freezeTime();
        $this->enable();
        $token = Str::random(64);
        MagicLink::factory()->{$state}()->create(['token' => hash('sha256', $token)]);
        $url = URL::temporarySignedRoute('magic-link.verify', now()->addMinutes(15), ['token' => $token]);

        $this->get($url)->assertRedirect(route('login'))->assertSessionHasErrors('magic_link');

        $this->assertGuest();
    }

    public function test_url_expiry_is_enforced_independently_of_database_expiry(): void
    {
        $this->freezeTime();
        $this->enable();
        $user = User::factory()->create();
        $token = Str::random(64);
        $link = MagicLink::factory()->for($user)->create(['token' => hash('sha256', $token)]);
        $url = URL::temporarySignedRoute('magic-link.verify', now()->subMinute(), ['token' => $token]);

        $this->get($url)->assertRedirect(route('login'))->assertSessionHasErrors('magic_link');

        $this->assertNull($link->fresh()->used_at);
        $this->assertGuest();
    }

    #[TestWith(['status', 'suspended'])]
    #[TestWith(['email', 'changed@example.com'])]
    #[TestWith(['is_guest', true])]
    public function test_link_cannot_sign_in_an_account_that_became_ineligible_after_issue(string $field, mixed $value): void
    {
        $this->enable();
        $user = User::factory()->create();
        [$link, $url] = $this->link($user);
        $user->update([$field => $value]);

        $this->get($url)->assertRedirect(route('login'))->assertSessionHasErrors('magic_link');

        $this->assertGuest();
        $this->assertNull($link->fresh()->used_at);
    }

    public function test_accounts_with_two_factor_continue_to_the_challenge_without_being_signed_in(): void
    {
        $this->enable();
        $user = User::factory()->withTwoFactor()->create();
        [$link, $url] = $this->link($user);

        $this->get($url)->assertRedirect(route('two-factor.login'))->assertSessionHas(SessionAuthenticator::PENDING_USER, $user->id);

        $this->assertGuest();
        $this->assertNotNull($link->fresh()->used_at);

        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-one'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_new_request_invalidates_the_previous_link(): void
    {
        $this->enable();
        $user = User::factory()->create();
        Notification::fake();
        $this->post(route('magic-link.request'), ['email' => $user->email])->assertSessionHasNoErrors();
        $old = MagicLink::query()->sole();
        $oldUrl = Notification::sent($user, EventNotification::class)->first()->data['link'];

        $this->post(route('magic-link.request'), ['email' => $user->email])->assertSessionHasNoErrors();

        $this->assertNotNull($old->fresh()->used_at);
        $this->assertSame(1, MagicLink::query()->valid()->count());
        $this->get($oldUrl)->assertRedirect(route('login'))->assertSessionHasErrors('magic_link');
        $newUrl = Notification::sent($user, EventNotification::class)->last()->data['link'];
        $this->get($newUrl)->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        Notification::assertSentToTimes($user, EventNotification::class, 2);
    }

    public function test_disabling_email_link_sign_in_blocks_links_already_issued(): void
    {
        $this->enable();
        [$link, $url] = $this->link(User::factory()->create());
        Setting::store(AuthenticationSettings::MAGIC_LINK_ENABLED, '0');

        $this->get($url)->assertNotFound();

        $this->assertNull($link->fresh()->used_at);
        $this->assertGuest();
    }

    public function test_already_signed_in_users_are_not_switched_to_the_link_owner(): void
    {
        $this->enable();
        [$link, $url] = $this->link(User::factory()->create());
        $current = User::factory()->create();

        $this->actingAs($current)->get($url)->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($current);
        $this->assertNull($link->fresh()->used_at);
    }

    public function test_requests_are_limited_to_three_per_hour_for_the_normalized_email_and_ip(): void
    {
        $this->freezeTime();
        $this->enable();
        Notification::fake();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post(route('magic-link.request'), ['email' => 'unknown@example.com'])->assertRedirect(route('login'));
        }

        $this->post(route('magic-link.request'), ['email' => 'UNKNOWN@example.com'])->assertTooManyRequests();
        $this->post(route('magic-link.request'), ['email' => 'other@example.com'])->assertRedirect(route('login'));
        $this->travel(61)->minutes();
        $this->post(route('magic-link.request'), ['email' => 'unknown@example.com'])->assertRedirect(route('login'));

        Notification::assertNothingSent();
    }

    #[TestWith([null])]
    #[TestWith([[]])]
    #[TestWith(['not-an-email'])]
    public function test_invalid_email_is_rejected_before_creating_or_sending_a_link(mixed $email): void
    {
        $this->enable();
        Notification::fake();

        $this->postJson(route('magic-link.request'), ['email' => $email])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('magic_links', 0);
        Notification::assertNothingSent();
    }

    public function test_queued_delivery_is_idempotent_and_uses_admin_editable_required_email_wording(): void
    {
        $this->enable();
        $user = User::factory()->create();
        [$link, $url] = $this->link($user);
        $event = app(NotificationCatalog::class)->find('auth.magic_link');
        app(NotificationSettings::class)->update($event, ['channels' => [], 'mail_subject' => 'Sign in within {expires_in} minutes', 'mail_body' => 'Use this sign-in link.', 'sms_body' => '']);
        Notification::fake();
        $job = new SendMagicLink($link->id, $url, 15);

        $job->handle(app(AuthenticationSettings::class));
        $job->handle(app(AuthenticationSettings::class));

        Notification::assertSentToTimes($user, EventNotification::class, 1);
        $notification = Notification::sent($user, EventNotification::class)->sole();
        $this->assertSame(['mail'], $notification->via($user));
        $this->assertSame('Sign in within 15 minutes', $notification->toMail($user)->subject);
        $this->assertSame($url, $notification->toMail($user)->actionUrl);
        $this->assertNotNull($link->fresh()->delivery_started_at);
    }

    public function test_delivery_skips_disabled_or_superseded_links(): void
    {
        $this->enable();
        [$link, $url] = $this->link(User::factory()->create());
        Notification::fake();
        $job = new SendMagicLink($link->id, $url, 15);
        Setting::store(AuthenticationSettings::MAGIC_LINK_ENABLED, '0');

        $job->handle(app(AuthenticationSettings::class));
        Setting::store(AuthenticationSettings::MAGIC_LINK_ENABLED, '1');
        $link->update(['used_at' => now()]);
        $job->handle(app(AuthenticationSettings::class));

        Notification::assertNothingSent();
        $this->assertNull($link->fresh()->delivery_started_at);
    }

    public function test_raw_sign_in_link_is_encrypted_in_the_database_queue(): void
    {
        $this->enable();
        [$link, $url] = $this->link(User::factory()->create());

        Queue::connection('database')->push(new SendMagicLink($link->id, $url, 15));

        $payload = json_decode(DB::table('jobs')->sole()->payload, true);
        $this->assertStringNotContainsString($url, $payload['data']['command']);
        $this->assertStringContainsString($url, Crypt::decrypt($payload['data']['command']));
    }

    public function test_delayed_delivery_reports_the_remaining_link_lifetime(): void
    {
        $this->freezeTime();
        $this->enable();
        $user = User::factory()->create();
        [$link, $url] = $this->link($user);
        Notification::fake();
        $this->travel(10)->minutes();

        (new SendMagicLink($link->id, $url, 15))->handle(app(AuthenticationSettings::class));

        Notification::assertSentTo($user, EventNotification::class, fn (EventNotification $notification) => $notification->event === 'auth.magic_link' && $notification->data['expires_in'] === 5);
    }

    public function test_queue_failure_keeps_the_generic_response_and_does_not_log_tokens_or_email(): void
    {
        $this->enable();
        $user = User::factory()->create();
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Sensitive provider details'));
        Log::spy();

        $this->post(route('magic-link.request'), ['email' => $user->email])->assertRedirect(route('login'))
            ->assertSessionHas('status', MagicLinkController::SENT_MESSAGE);

        Log::shouldHaveReceived('warning')->once()->with('Magic-link email could not be queued.', ['exception_type' => RuntimeException::class]);
        $this->assertSame(0, MagicLink::query()->valid()->count());
    }

    private function enable(): void
    {
        Setting::store(AuthenticationSettings::MAGIC_LINK_ENABLED, '1');
    }

    /**
     * @return array{MagicLink, string}
     */
    private function link(User $user): array
    {
        $token = Str::random(64);
        $link = MagicLink::factory()->for($user)->create(['token' => hash('sha256', $token)]);

        return [$link, URL::temporarySignedRoute('magic-link.verify', $link->expires_at, ['token' => $token])];
    }
}
