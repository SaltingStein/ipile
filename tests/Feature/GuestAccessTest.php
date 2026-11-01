<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\AuthenticationSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Guest quick-start is an operator's choice (CORE-104): off by default, kept on for installs that already had guests,
 * with optional wording and an optional clean-up of unused guest accounts.
 */
class GuestAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_access_is_off_by_default_and_the_home_page_offers_sign_in_instead(): void
    {
        $this->assertFalse(app(AuthenticationSettings::class)->guestAccessEnabled());

        $this->post(route('guest.start'), ['nickname' => 'Ada'])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
        $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canStartGuest', false)
            ->where('canRegister', true));
    }

    public function test_the_migration_keeps_guest_access_on_only_for_installs_that_already_have_guests(): void
    {
        $migration = require database_path('migrations/2026_10_22_175403_keep_guest_access_for_installs_with_guests.php');

        User::factory()->create();
        $migration->up();
        $this->assertDatabaseMissing('settings', ['key' => AuthenticationSettings::GUEST_ACCESS_ENABLED]);

        User::factory()->guest()->create();
        $migration->up();
        $this->assertTrue(app(AuthenticationSettings::class)->guestAccessEnabled());

        // An administrator's earlier choice is never overridden.
        Setting::store(AuthenticationSettings::GUEST_ACCESS_ENABLED, '0');
        $migration->up();
        $this->assertFalse(app(AuthenticationSettings::class)->guestAccessEnabled());
    }

    public function test_saved_wording_reaches_the_home_page_and_blank_wording_falls_back_to_the_layout(): void
    {
        $this->actingAs($this->manager())->put(route('admin.authentication.update'), [
            ...$this->requiredSettings(),
            'guest_access_enabled' => true,
            'guest_button_label' => '  Try the demo ',
            'guest_field_label' => 'Your first name',
        ])->assertSessionHasNoErrors();
        auth()->logout();

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
            ->where('canStartGuest', true)
            ->where('guestStart', ['buttonLabel' => 'Try the demo', 'fieldLabel' => 'Your first name']));

        app(AuthenticationSettings::class)->update([...$this->requiredSettings(), 'guest_button_label' => null, 'guest_field_label' => '']);

        $this->get(route('home'))->assertInertia(fn (Assert $page) => $page
            ->where('guestStart', ['buttonLabel' => null, 'fieldLabel' => null]));
    }

    public function test_wording_and_retention_are_validated(): void
    {
        $this->actingAs($this->manager())->put(route('admin.authentication.update'), [
            ...$this->requiredSettings(),
            'guest_button_label' => str_repeat('a', AuthenticationSettings::MAX_GUEST_LABEL_LENGTH + 1),
            'guest_retention_days' => 3,
        ])->assertSessionHasErrors(['guest_button_label', 'guest_retention_days']);

        $this->put(route('admin.authentication.update'), [...$this->requiredSettings(), 'guest_retention_days' => 0])
            ->assertSessionHasNoErrors();
        $this->put(route('admin.authentication.update'), [...$this->requiredSettings(), 'guest_retention_days' => 30])
            ->assertSessionHasNoErrors();

        $this->assertSame(30, app(AuthenticationSettings::class)->guestRetentionDays());
    }

    public function test_pruning_deletes_only_guests_unused_for_the_retention_period(): void
    {
        $old = User::factory()->guest()->create(['last_login_at' => now()->subDays(40), 'created_at' => now()->subDays(60)]);
        $old->createToken('old');
        DB::table('sessions')->insert(['id' => 'stale', 'user_id' => $old->id, 'payload' => '', 'last_activity' => now()->subDays(35)->getTimestamp()]);
        $recent = User::factory()->guest()->create(['last_login_at' => now()->subDays(5), 'created_at' => now()->subDays(60)]);
        $stillBrowsing = User::factory()->guest()->create(['last_login_at' => now()->subDays(40), 'created_at' => now()->subDays(60)]);
        DB::table('sessions')->insert(['id' => 'live', 'user_id' => $stillBrowsing->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);
        $fullAccount = User::factory()->create(['last_login_at' => now()->subYear(), 'created_at' => now()->subYear()]);

        $this->artisan('guests:prune')->expectsOutputToContain('Guest accounts are kept')->assertSuccessful();
        $this->assertModelExists($old);

        Setting::store(AuthenticationSettings::GUEST_RETENTION_DAYS, '30');
        $this->artisan('guests:prune')->expectsOutputToContain('Deleted 1 guest accounts')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $old->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'stale']);
        $this->assertModelExists($recent);
        $this->assertModelExists($stillBrowsing);
        $this->assertModelExists($fullAccount);
    }

    public function test_a_returning_guest_counts_as_active(): void
    {
        $guest = User::factory()->guest()->create(['last_login_at' => now()->subDays(40)]);
        $member = User::factory()->create(['last_login_at' => now()->subDays(40)]);

        event(new Login('web', $guest, true));
        event(new Login('web', $member, true));

        $this->assertTrue($guest->fresh()->last_login_at->isToday());
        $this->assertFalse($member->fresh()->last_login_at->isToday());
    }

    private function manager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        return User::factory()->create()->givePermissionTo('manage_settings');
    }

    /**
     * @return array{registration_enabled: bool, trusted_device_days: int, two_factor_required_roles: list<string>}
     */
    private function requiredSettings(): array
    {
        return ['registration_enabled' => true, 'trusted_device_days' => 30, 'two_factor_required_roles' => []];
    }
}
