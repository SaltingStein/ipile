<?php

namespace Tests\Feature\Teams;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\EventNotification;
use App\Services\Teams\TeamRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TeamInvitationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_invites_an_email_and_the_invitation_is_emailed(): void
    {
        Notification::fake();
        $team = Team::factory()->create();
        $admin = $this->addMember($team, TeamRole::Admin);

        $response = $this->actingAs($admin)->post(route('team-invitations.store', $team), ['email' => 'New@Example.com', 'role' => 'admin']);

        $response->assertSessionHas('status', 'Invitation sent to New@Example.com.');
        $this->assertDatabaseHas('team_invitations', ['team_id' => $team->id, 'email' => 'new@example.com', 'role' => 'admin', 'invited_by' => $admin->id]);
        Notification::assertSentOnDemand(
            EventNotification::class,
            fn (EventNotification $notification, array $channels, AnonymousNotifiable $notifiable) => $notification->event === 'team.invitation'
                && $notifiable->routes['mail'] === 'new@example.com'
                && $notification->data['team'] === $team->name,
        );
    }

    public function test_member_cannot_invite(): void
    {
        Notification::fake();
        $team = Team::factory()->create();
        $member = $this->addMember($team, TeamRole::Member);

        $this->actingAs($member)
            ->post(route('team-invitations.store', $team), ['email' => 'new@example.com', 'role' => 'member'])
            ->assertForbidden();

        $this->assertDatabaseCount('team_invitations', 0);
        Notification::assertNothingSent();
    }

    public function test_inviting_someone_already_on_the_team_is_refused(): void
    {
        $team = Team::factory()->create();
        $member = $this->addMember($team, TeamRole::Member);

        $response = $this->actingAs($team->owner)->post(route('team-invitations.store', $team), ['email' => $member->email, 'role' => 'member']);

        $response->assertSessionHasErrors(['email' => 'This person is already on the team.']);
        $this->assertDatabaseCount('team_invitations', 0);
    }

    public function test_inviting_the_same_email_twice_is_refused(): void
    {
        $team = Team::factory()->create();
        $team->invitations()->create(['email' => 'new@example.com', 'role' => 'member']);

        $response = $this->actingAs($team->owner)->post(route('team-invitations.store', $team), ['email' => 'new@example.com', 'role' => 'member']);

        $response->assertSessionHasErrors(['email' => 'This email address has already been invited.']);
        $this->assertDatabaseCount('team_invitations', 1);
    }

    public function test_invitation_email_escapes_the_team_and_inviter_names(): void
    {
        $notification = new EventNotification('team.invitation', [
            'team' => "O'Reilly <script>alert('team')</script>",
            'inviter' => '<b>Ada</b>',
            'link' => 'https://example.com/accept',
            'expires_in_days' => 7,
        ]);

        $html = (string) $notification->toMail(new AnonymousNotifiable)->render();

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString("<script>alert('team')</script>", $html);
        $this->assertStringNotContainsString('<b>Ada</b>', $html);
    }

    public function test_invited_user_accepts_from_the_signed_link(): void
    {
        $invitation = TeamInvitation::factory()->create(['email' => 'ada@example.com', 'role' => 'admin']);
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $response = $this->actingAs($user)->get($this->acceptUrl($invitation));

        $response->assertRedirect(route('teams.show', $invitation->team_id));
        $this->assertSame(TeamRole::Admin, $invitation->team->roleOf($user));
        $this->assertSame($invitation->team_id, $user->fresh()->current_team_id);
        $this->assertModelMissing($invitation);
    }

    public function test_a_different_account_cannot_accept_the_invitation(): void
    {
        $invitation = TeamInvitation::factory()->create(['email' => 'ada@example.com']);
        $intruder = User::factory()->create(['email' => 'intruder@example.com']);

        $response = $this->actingAs($intruder)->get($this->acceptUrl($invitation));

        $response->assertRedirect(route('teams.index'));
        $response->assertSessionHasErrors(['invitation' => 'This invitation was sent to a different email address. Sign in with that address to accept it.']);
        $this->assertFalse($invitation->team->hasUser($intruder));
        $this->assertModelExists($invitation);
    }

    public function test_unsigned_accept_link_is_rejected(): void
    {
        $invitation = TeamInvitation::factory()->create(['email' => 'ada@example.com']);
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->actingAs($user)->get(route('team-invitations.accept', $invitation))->assertForbidden();

        $this->assertFalse($invitation->team->hasUser($user));
    }

    public function test_signed_out_invitee_is_returned_to_the_invitation_after_signing_in(): void
    {
        $invitation = TeamInvitation::factory()->create(['email' => 'ada@example.com']);
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $acceptUrl = $this->acceptUrl($invitation);
        $this->get($acceptUrl)->assertRedirect(route('login'));

        $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $response->assertRedirect($acceptUrl);
    }

    public function test_admin_cancels_a_pending_invitation(): void
    {
        $invitation = TeamInvitation::factory()->create();

        $this->actingAs($invitation->team->owner)->delete(route('team-invitations.destroy', [$invitation->team, $invitation]));

        $this->assertModelMissing($invitation);
    }

    public function test_invitation_of_another_team_cannot_be_cancelled_through_your_team(): void
    {
        $team = Team::factory()->create();
        $otherInvitation = TeamInvitation::factory()->create();

        $this->actingAs($team->owner)
            ->delete(route('team-invitations.destroy', [$team, $otherInvitation]))
            ->assertNotFound();

        $this->assertModelExists($otherInvitation);
    }

    private function acceptUrl(TeamInvitation $invitation): string
    {
        return $invitation->acceptUrl();
    }

    private function addMember(Team $team, TeamRole $role): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role->value]);

        return $user;
    }
}
