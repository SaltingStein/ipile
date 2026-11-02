<?php

namespace Tests\Feature\Settings;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\SocialAccount;
use App\Models\Team;
use App\Models\TrustedDevice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_an_account_removes_personal_data_and_signs_the_person_out(): void
    {
        $user = User::factory()->withTwoFactor()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'phone_number' => '+2348012345678']);
        $user->createToken('phone');

        $this->confirmed($user)->delete(route('account.destroy'), ['confirm' => 'DELETE'])
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertSoftDeleted($user);
        $deleted = User::withTrashed()->find($user->id);
        $this->assertSame('Deleted user', $deleted->name);
        $this->assertNull($deleted->email);
        $this->assertNull($deleted->phone_number);
        $this->assertNull($deleted->password);
        $this->assertNull($deleted->two_factor_secret);
        $this->assertSame(0, $deleted->tokens()->count());
    }

    public function test_deleting_needs_the_typed_confirmation(): void
    {
        $user = User::factory()->create();

        $this->confirmed($user)->delete(route('account.destroy'), ['confirm' => 'delete'])
            ->assertSessionHasErrors('confirm');

        $this->assertNotSoftDeleted($user);
    }

    public function test_deleting_needs_a_recent_password_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete(route('account.destroy'), ['confirm' => 'DELETE'])
            ->assertRedirect(route('password.confirm'));

        $this->assertNotSoftDeleted($user);
    }

    public function test_sign_in_methods_are_revoked(): void
    {
        $user = User::factory()->create();
        SocialAccount::query()->forceCreate(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-1', 'email' => 'ada@gmail.com']);
        TrustedDevice::factory()->for($user)->create();

        $this->confirmed($user)->delete(route('account.destroy'), ['confirm' => 'DELETE']);

        $this->assertDatabaseCount('social_accounts', 0);
        $this->assertDatabaseCount('trusted_devices', 0);
    }

    public function test_payments_are_kept_as_financial_records_without_personal_details(): void
    {
        $user = User::factory()->create(['email' => 'payer@example.com']);
        $payment = Payment::factory()->for($user)->create();

        $this->confirmed($user)->delete(route('account.destroy'), ['confirm' => 'DELETE']);

        $this->assertNotSoftDeleted($payment);
        $this->assertNull(User::withTrashed()->find($user->id)->email);
    }

    public function test_a_team_with_other_members_must_be_handed_over_first(): void
    {
        $team = Team::factory()->create(['name' => 'Ops']);
        $team->users()->attach(User::factory()->create(), ['role' => 'member']);

        $this->confirmed($team->owner)->delete(route('account.destroy'), ['confirm' => 'DELETE'])
            ->assertSessionHasErrors(['account' => 'Transfer or delete the teams you own that have other members first: Ops.']);

        $this->assertNotSoftDeleted($team->owner);
    }

    public function test_a_team_only_the_owner_uses_is_deleted_with_the_account(): void
    {
        $team = Team::factory()->create();

        $this->confirmed($team->owner)->delete(route('account.destroy'), ['confirm' => 'DELETE'])->assertRedirect('/');

        $this->assertModelMissing($team);
    }

    public function test_the_last_super_admin_cannot_delete_their_account(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create()->assignRole('super_admin');

        $this->confirmed($admin)->delete(route('account.destroy'), ['confirm' => 'DELETE'])
            ->assertSessionHasErrors('account');

        $this->assertNotSoftDeleted($admin);
    }

    public function test_the_deletion_is_audited_without_personal_data(): void
    {
        $user = User::factory()->create(['email' => 'audited@example.com']);

        $this->confirmed($user)->delete(route('account.destroy'), ['confirm' => 'DELETE']);

        $this->assertSame(1, AuditLog::query()->where('action', 'account.deleted')->where('auditable_id', $user->id)->count());
        $this->assertStringNotContainsString('audited@example.com', json_encode(AuditLog::query()->pluck('changes')));
    }

    public function test_the_anonymous_account_is_purged_after_the_retention_window(): void
    {
        $user = User::factory()->create();
        $payment = Payment::factory()->for($user)->create();
        $this->confirmed($user)->delete(route('account.destroy'), ['confirm' => 'DELETE']);

        $this->purgeDeletedRecords();

        $this->assertModelMissing($user);
        $this->assertNull($payment->fresh()->user_id);
    }

    public function test_the_data_export_contains_the_persons_data_and_no_secrets(): void
    {
        $user = User::factory()->withTwoFactor()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
        Payment::factory()->for($user)->create(['amount' => '2500.00', 'reference' => 'PAY-1']);

        $response = $this->confirmed($user)->get(route('account.export'));

        $response->assertOk();
        $response->assertHeader('Content-Disposition', 'attachment; filename="my-data-'.now()->format('Y-m-d').'.json"');
        $response->assertJsonPath('account.email', 'ada@example.com');
        $response->assertJsonPath('account.two_factor_enabled', true);
        $response->assertJsonPath('payments.0.reference', 'PAY-1');
        $response->assertJsonPath('payments.0.amount', '2500.00');
        $this->assertStringNotContainsString('password', $response->getContent());
        $this->assertStringNotContainsString('two_factor_secret', $response->getContent());
        $this->assertSame(1, AuditLog::query()->where('action', 'account.data_exported')->count());
    }

    public function test_the_api_offers_export_and_deletion_for_mobile_apps(): void
    {
        $user = User::factory()->create(['email' => 'mobile@example.com']);
        Sanctum::actingAs($user);

        $this->getJson(route('api.v1.auth.user.export'))->assertOk()->assertJsonPath('data.account.email', 'mobile@example.com');
        $this->deleteJson(route('api.v1.auth.user.destroy'), ['confirm' => 'nope'])->assertUnprocessable();
        $this->deleteJson(route('api.v1.auth.user.destroy'), ['confirm' => 'DELETE'])->assertNoContent();

        $this->assertSoftDeleted($user);
    }

    public function test_the_api_explains_what_must_happen_before_deletion(): void
    {
        $team = Team::factory()->create(['name' => 'Ops']);
        $team->users()->attach(User::factory()->create(), ['role' => 'member']);
        Sanctum::actingAs($team->owner);

        $this->deleteJson(route('api.v1.auth.user.destroy'), ['confirm' => 'DELETE'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Transfer or delete the teams you own that have other members first: Ops.');
    }

    private function confirmed(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }
}
