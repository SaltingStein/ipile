<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmailVerificationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_user_sees_the_verification_notice(): void
    {
        $response = $this->actingAs(User::factory()->unverified()->create())->get(route('verification.notice'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->component('Auth/VerifyEmail'));
    }

    public function test_verified_user_is_sent_from_the_notice_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('verification.notice'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_guest_account_is_sent_to_registration(): void
    {
        $this->actingAs(User::factory()->guest()->create())
            ->get(route('verification.notice'))
            ->assertRedirect(route('register'));
    }

    public function test_signed_link_verifies_the_email(): void
    {
        $user = User::factory()->unverified()->create();
        Event::fake([Verified::class]);

        $response = $this->actingAs($user)->get($this->verificationUrl($user, sha1($user->email)));

        $response->assertRedirect(route('dashboard'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class);
    }

    public function test_link_with_the_wrong_hash_does_not_verify(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get($this->verificationUrl($user, sha1('someone-else@example.com')));

        $response->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_resend_sends_a_new_verification_email(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->post(route('verification.send'));

        $response->assertSessionHas('status', 'A new verification link has been sent to your email address.');
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    private function verificationUrl(User $user, string $hash): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => $hash]);
    }
}
