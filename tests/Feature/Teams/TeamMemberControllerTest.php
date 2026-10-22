<?php

namespace Tests\Feature\Teams;

use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers TeamMemberController and TeamOwnershipController.
 */
class TeamMemberControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_changes_a_members_role(): void
    {
        $team = Team::factory()->create();
        $admin = $this->addMember($team, TeamRole::Admin);
        $member = $this->addMember($team, TeamRole::Member);

        $this->actingAs($admin)->put(route('team-members.update', [$team, $member]), ['role' => 'admin']);

        $this->assertSame(TeamRole::Admin, $team->roleOf($member));
    }

    public function test_member_cannot_change_roles(): void
    {
        $team = Team::factory()->create();
        $member = $this->addMember($team, TeamRole::Member);
        $other = $this->addMember($team, TeamRole::Member);

        $this->actingAs($member)->put(route('team-members.update', [$team, $other]), ['role' => 'admin'])->assertForbidden();

        $this->assertSame(TeamRole::Member, $team->roleOf($other));
    }

    public function test_the_owners_role_cannot_be_changed(): void
    {
        $team = Team::factory()->create();
        $admin = $this->addMember($team, TeamRole::Admin);

        $response = $this->actingAs($admin)->put(route('team-members.update', [$team, $team->owner]), ['role' => 'member']);

        $response->assertSessionHasErrors(['role' => 'The team owner cannot be changed or removed this way. Transfer ownership first.']);
        $this->assertTrue($team->fresh()->isOwnedBy($team->owner));
    }

    public function test_unknown_role_is_rejected(): void
    {
        $team = Team::factory()->create();
        $member = $this->addMember($team, TeamRole::Member);

        $this->actingAs($team->owner)
            ->put(route('team-members.update', [$team, $member]), ['role' => 'owner'])
            ->assertSessionHasErrors(['role' => 'The selected role is invalid.']);
    }

    public function test_admin_removes_a_member_who_loses_it_as_current_team(): void
    {
        $team = Team::factory()->create();
        $admin = $this->addMember($team, TeamRole::Admin);
        $member = $this->addMember($team, TeamRole::Member);
        $member->switchTeam($team);

        $this->actingAs($admin)->delete(route('team-members.destroy', [$team, $member]));

        $this->assertFalse($team->hasUser($member));
        $this->assertNull($member->fresh()->current_team_id);
    }

    public function test_member_can_leave_the_team(): void
    {
        $team = Team::factory()->create();
        $member = $this->addMember($team, TeamRole::Member);

        $response = $this->actingAs($member)->delete(route('team-members.destroy', [$team, $member]));

        $response->assertRedirect(route('teams.index'));
        $this->assertFalse($team->hasUser($member));
    }

    public function test_member_cannot_remove_someone_else(): void
    {
        $team = Team::factory()->create();
        $member = $this->addMember($team, TeamRole::Member);
        $other = $this->addMember($team, TeamRole::Member);

        $this->actingAs($member)->delete(route('team-members.destroy', [$team, $other]))->assertForbidden();

        $this->assertTrue($team->hasUser($other));
    }

    public function test_owner_cannot_leave_without_transferring_ownership(): void
    {
        $team = Team::factory()->create();

        $response = $this->actingAs($team->owner)->delete(route('team-members.destroy', [$team, $team->owner]));

        $response->assertSessionHasErrors(['member' => 'The team owner cannot be changed or removed this way. Transfer ownership first.']);
        $this->assertTrue($team->fresh()->isOwnedBy($team->owner));
    }

    public function test_outsider_gets_404_when_removing_members(): void
    {
        $team = Team::factory()->create();
        $member = $this->addMember($team, TeamRole::Member);

        $this->actingAs(User::factory()->create())->delete(route('team-members.destroy', [$team, $member]))->assertNotFound();

        $this->assertTrue($team->hasUser($member));
    }

    public function test_owner_transfers_ownership_and_stays_on_as_admin(): void
    {
        $team = Team::factory()->create();
        $previousOwner = $team->owner;
        $member = $this->addMember($team, TeamRole::Member);

        $this->actingAs($previousOwner)->put(route('team-owner.update', $team), ['user_id' => $member->id]);

        $team->refresh();
        $this->assertTrue($team->isOwnedBy($member));
        $this->assertNull($team->roleOf($member));
        $this->assertSame(TeamRole::Admin, $team->roleOf($previousOwner));
    }

    public function test_admin_cannot_transfer_ownership(): void
    {
        $team = Team::factory()->create();
        $admin = $this->addMember($team, TeamRole::Admin);

        $this->actingAs($admin)->put(route('team-owner.update', $team), ['user_id' => $admin->id])->assertForbidden();

        $this->assertFalse($team->fresh()->isOwnedBy($admin));
    }

    public function test_ownership_cannot_go_to_someone_outside_the_team(): void
    {
        $team = Team::factory()->create();
        $outsider = User::factory()->create();

        $response = $this->actingAs($team->owner)->put(route('team-owner.update', $team), ['user_id' => $outsider->id]);

        $response->assertSessionHasErrors(['user_id' => 'This person is not a member of the team.']);
        $this->assertFalse($team->fresh()->isOwnedBy($outsider));
    }

    private function addMember(Team $team, TeamRole $role): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role->value]);

        return $user;
    }
}
