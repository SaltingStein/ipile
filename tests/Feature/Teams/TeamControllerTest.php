<?php

namespace Tests\Feature\Teams;

use App\Models\Team;
use App\Models\User;
use App\Services\Teams\TeamRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers TeamController and CurrentTeamController.
 */
class TeamControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_account_is_sent_to_registration(): void
    {
        $this->actingAs(User::factory()->guest()->create())
            ->get(route('teams.index'))
            ->assertRedirect(route('register'));
    }

    public function test_index_lists_owned_and_member_teams_with_the_users_role_only(): void
    {
        $user = User::factory()->create();
        $owned = Team::factory()->for($user, 'owner')->create(['name' => 'Alpha']);
        $joined = Team::factory()->create(['name' => 'Beta']);
        $joined->users()->attach($user, ['role' => TeamRole::Admin->value]);
        Team::factory()->create(['name' => 'Someone else']);

        $response = $this->actingAs($user)->get(route('teams.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Teams/Index')
            ->has('teams', 2)
            ->where('teams.0.id', $owned->id)
            ->where('teams.0.current_user.role', 'owner')
            ->where('teams.1.id', $joined->id)
            ->where('teams.1.current_user.role', 'admin'));
    }

    public function test_creating_a_team_makes_the_user_its_owner_and_current_team(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('teams.store'), ['name' => 'Lagos Ops', 'description' => 'Operations']);

        $team = Team::query()->where('name', 'Lagos Ops')->firstOrFail();
        $response->assertRedirect(route('teams.show', $team));
        $this->assertTrue($team->isOwnedBy($user));
        $this->assertSame($team->id, $user->fresh()->current_team_id);
    }

    public function test_team_name_is_required(): void
    {
        $response = $this->actingAs(User::factory()->create())->post(route('teams.store'), ['name' => '']);

        $response->assertSessionHasErrors(['name' => 'The name field is required.']);
        $this->assertDatabaseCount('teams', 0);
    }

    public function test_member_sees_the_team_but_not_its_invitations(): void
    {
        $team = Team::factory()->create();
        $member = $this->addMember($team, TeamRole::Member);
        $team->invitations()->create(['email' => 'pending@example.com', 'role' => 'member']);

        $response = $this->actingAs($member)->get(route('teams.show', $team));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Teams/Show')
            ->where('team.owner.id', $team->owner_id)
            ->where('team.members.0.id', $member->id)
            ->where('team.current_user.can_manage', false)
            ->missing('team.invitations'));
    }

    public function test_admin_sees_pending_invitations(): void
    {
        $team = Team::factory()->create();
        $admin = $this->addMember($team, TeamRole::Admin);
        $team->invitations()->create(['email' => 'pending@example.com', 'role' => 'member']);

        $this->actingAs($admin)->get(route('teams.show', $team))
            ->assertInertia(fn (Assert $page) => $page->where('team.invitations.0.email', 'pending@example.com'));
    }

    public function test_outsider_gets_404_for_another_team(): void
    {
        $team = Team::factory()->create();

        $this->actingAs(User::factory()->create())->get(route('teams.show', $team))->assertNotFound();
    }

    public function test_admin_can_rename_the_team(): void
    {
        $team = Team::factory()->create();
        $admin = $this->addMember($team, TeamRole::Admin);

        $this->actingAs($admin)->put(route('teams.update', $team), ['name' => 'Renamed']);

        $this->assertSame('Renamed', $team->fresh()->name);
    }

    public function test_member_cannot_rename_the_team(): void
    {
        $team = Team::factory()->create(['name' => 'Original']);
        $member = $this->addMember($team, TeamRole::Member);

        $this->actingAs($member)->put(route('teams.update', $team), ['name' => 'Renamed'])->assertForbidden();

        $this->assertSame('Original', $team->fresh()->name);
    }

    public function test_owner_deletes_the_team_and_members_lose_it_as_current_team(): void
    {
        $team = Team::factory()->create();
        $member = $this->addMember($team, TeamRole::Member);
        $member->switchTeam($team);

        $response = $this->actingAs($team->owner)->delete(route('teams.destroy', $team));

        $response->assertRedirect(route('teams.index'));
        $this->assertModelMissing($team);
        $this->assertNull($member->fresh()->current_team_id);
    }

    public function test_admin_cannot_delete_the_team(): void
    {
        $team = Team::factory()->create();
        $admin = $this->addMember($team, TeamRole::Admin);

        $this->actingAs($admin)->delete(route('teams.destroy', $team))->assertForbidden();

        $this->assertModelExists($team);
    }

    public function test_user_can_switch_to_a_team_they_belong_to(): void
    {
        $team = Team::factory()->create();
        $member = $this->addMember($team, TeamRole::Member);

        $this->actingAs($member)->put(route('current-team.update'), ['team_id' => $team->id]);

        $this->assertSame($team->id, $member->fresh()->current_team_id);
    }

    public function test_switching_to_a_team_the_user_does_not_belong_to_returns_404(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();

        $this->actingAs($user)->put(route('current-team.update'), ['team_id' => $team->id])->assertNotFound();

        $this->assertNull($user->fresh()->current_team_id);
    }

    private function addMember(Team $team, TeamRole $role): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role->value]);

        return $user;
    }
}
