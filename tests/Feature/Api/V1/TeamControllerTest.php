<?php

namespace Tests\Feature\Api\V1;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Teams\TeamRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers Api\V1\TeamController and Api\V1\TeamMembershipController.
 */
class TeamControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_401_without_a_token(): void
    {
        $this->getJson(route('api.v1.teams.index'))->assertUnauthorized();
    }

    public function test_index_returns_only_the_users_teams(): void
    {
        $user = User::factory()->create();
        $owned = Team::factory()->for($user, 'owner')->create();
        Team::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson(route('api.v1.teams.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $owned->id)
            ->assertJsonPath('data.0.current_user.role', 'owner');
    }

    public function test_store_creates_a_team_and_returns_201(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.teams.store'), ['name' => 'Abuja Sales']);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Abuja Sales');
        $response->assertJsonPath('data.owner.id', $user->id);
        $this->assertSame($response->json('data.id'), $user->fresh()->current_team_id);
    }

    public function test_show_returns_404_for_a_team_the_user_is_not_on(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson(route('api.v1.teams.show', Team::factory()->create()))->assertNotFound();
    }

    public function test_member_cannot_update_and_gets_403(): void
    {
        $team = Team::factory()->create(['name' => 'Original']);
        Sanctum::actingAs($this->addMember($team, TeamRole::Member));

        $this->putJson(route('api.v1.teams.update', $team), ['name' => 'Renamed'])->assertForbidden();

        $this->assertSame('Original', $team->fresh()->name);
    }

    public function test_owner_deletes_the_team_and_gets_204(): void
    {
        $team = Team::factory()->create();
        Sanctum::actingAs($team->owner);

        $this->deleteJson(route('api.v1.teams.destroy', $team))->assertNoContent();

        $this->assertModelMissing($team);
    }

    public function test_admin_invites_and_gets_201(): void
    {
        Notification::fake();
        $team = Team::factory()->create();
        Sanctum::actingAs($this->addMember($team, TeamRole::Admin));

        $this->postJson(route('api.v1.team-invitations.store', $team), ['email' => 'new@example.com', 'role' => 'member'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'new@example.com');

        $this->assertDatabaseHas('team_invitations', ['team_id' => $team->id, 'email' => 'new@example.com']);
    }

    public function test_duplicate_invitation_returns_422(): void
    {
        $team = Team::factory()->create();
        $team->invitations()->create(['email' => 'new@example.com', 'role' => 'member']);
        Sanctum::actingAs($team->owner);

        $this->postJson(route('api.v1.team-invitations.store', $team), ['email' => 'new@example.com', 'role' => 'member'])
            ->assertJsonValidationErrors(['email' => 'This email address has already been invited.']);
    }

    public function test_verified_invitee_accepts_the_invitation(): void
    {
        $invitation = TeamInvitation::factory()->create(['email' => 'ada@example.com', 'role' => 'member']);
        $user = User::factory()->create(['email' => 'ada@example.com']);
        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.team-invitations.accept', $invitation))
            ->assertOk()
            ->assertJsonPath('data.id', $invitation->team_id);

        $this->assertSame(TeamRole::Member, $invitation->team->roleOf($user));
        $this->assertModelMissing($invitation);
    }

    public function test_unverified_account_cannot_accept_over_the_api(): void
    {
        $invitation = TeamInvitation::factory()->create(['email' => 'ada@example.com']);
        $user = User::factory()->unverified()->create(['email' => 'ada@example.com']);
        Sanctum::actingAs($user);

        $this->postJson(route('api.v1.team-invitations.accept', $invitation))->assertForbidden();

        $this->assertFalse($invitation->team->hasUser($user));
    }

    public function test_invitation_for_another_email_returns_422(): void
    {
        $invitation = TeamInvitation::factory()->create(['email' => 'ada@example.com']);
        $intruder = User::factory()->create(['email' => 'intruder@example.com']);
        Sanctum::actingAs($intruder);

        $this->postJson(route('api.v1.team-invitations.accept', $invitation))->assertJsonValidationErrors(['invitation']);

        $this->assertFalse($invitation->team->hasUser($intruder));
    }

    public function test_member_leaves_the_team(): void
    {
        $team = Team::factory()->create();
        $member = $this->addMember($team, TeamRole::Member);
        Sanctum::actingAs($member);

        $this->deleteJson(route('api.v1.team-members.destroy', [$team, $member]))->assertNoContent();

        $this->assertFalse($team->hasUser($member));
    }

    public function test_owner_transfers_ownership(): void
    {
        $team = Team::factory()->create();
        $member = $this->addMember($team, TeamRole::Member);
        Sanctum::actingAs($team->owner);

        $this->putJson(route('api.v1.team-owner.update', $team), ['user_id' => $member->id])->assertNoContent();

        $this->assertTrue($team->fresh()->isOwnedBy($member));
    }

    public function test_switching_to_a_foreign_team_returns_404(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson(route('api.v1.current-team.update'), ['team_id' => Team::factory()->create()->id])->assertNotFound();

        $this->assertNull($user->fresh()->current_team_id);
    }

    private function addMember(Team $team, TeamRole $role): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role->value]);

        return $user;
    }
}
