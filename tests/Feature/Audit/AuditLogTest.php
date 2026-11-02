<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Setup\SuperAdminSetup;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_changes_record_who_made_them_and_the_roles_before_and_after(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_access');
        $member = User::factory()->create();

        $this->actingAs($manager)->post(route('admin.access.update'), ['user_id' => $member->id, 'role' => 'admin']);

        $entry = AuditLog::query()->where('action', 'user.roles_changed')->sole();
        $this->assertSame($manager->id, $entry->actor_id);
        $this->assertTrue($entry->auditable->is($member));
        $this->assertSame(['roles' => []], $entry->changes['before']);
        $this->assertSame(['roles' => ['admin']], $entry->changes['after']);
        $this->assertNotNull($entry->ip_address);
    }

    public function test_granting_a_permission_to_a_role_is_recorded(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_access');

        $this->actingAs($manager)->post(route('admin.access.update'), ['role' => 'support', 'permission' => 'view_billing']);

        $entry = AuditLog::query()->where('action', 'role.permission_granted')->sole();
        $this->assertTrue($entry->auditable->is(Role::findByName('support')));
        $this->assertSame(['role' => 'support', 'permission' => 'view_billing'], $entry->changes['after']);
    }

    public function test_personal_details_are_recorded_as_changed_but_never_stored(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com', 'name' => 'Ada Old']);

        $user->update(['email' => 'new@example.com', 'name' => 'Ada New']);

        $entry = AuditLog::query()->where('action', 'user.updated')->sole();
        $this->assertSame(['email' => '[redacted]', 'name' => '[redacted]'], $entry->changes['after']);
        $raw = json_encode(AuditLog::query()->pluck('changes'));
        $this->assertStringNotContainsString('old@example.com', $raw);
        $this->assertStringNotContainsString('new@example.com', $raw);
        $this->assertStringNotContainsString('Ada', $raw);
    }

    public function test_passwords_are_never_written_to_the_log(): void
    {
        $user = User::factory()->create();

        $user->update(['password' => 'a-new-secret-password']);

        $this->assertStringNotContainsString('password', json_encode(AuditLog::query()->pluck('changes')));
        $this->assertStringNotContainsString('$2y$', json_encode(AuditLog::query()->pluck('changes')));
    }

    public function test_status_changes_such_as_a_suspension_are_recorded_with_their_values(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $user->update(['status' => 'suspended']);

        $entry = AuditLog::query()->where('action', 'user.updated')->sole();
        $this->assertSame(['status' => 'active'], $entry->changes['before']);
        $this->assertSame(['status' => 'suspended'], $entry->changes['after']);
    }

    public function test_routine_sign_in_bookkeeping_is_not_recorded(): void
    {
        $user = User::factory()->create();

        $user->update(['last_login_at' => now()]);

        $this->assertSame(0, AuditLog::query()->where('action', 'user.updated')->count());
    }

    public function test_setting_changes_name_the_setting_and_keep_both_values(): void
    {
        Setting::store('site.theme', 'dark');
        Setting::store('site.theme', 'light');

        $entry = AuditLog::query()->where('action', 'setting.updated')->sole();
        $this->assertSame(['key' => 'site.theme', 'value' => 'dark'], $entry->changes['before']);
        $this->assertSame(['key' => 'site.theme', 'value' => 'light'], $entry->changes['after']);
    }

    public function test_settings_that_hold_credentials_never_log_their_value(): void
    {
        Setting::store(SuperAdminSetup::KEY_HASH, 'hash-of-the-setup-key');

        $this->assertStringNotContainsString('hash-of-the-setup-key', json_encode(AuditLog::query()->pluck('changes')));
        $this->assertSame('[redacted]', AuditLog::query()->where('action', 'setting.created')->sole()->changes['after']['value']);
    }

    public function test_integration_credentials_are_never_logged(): void
    {
        $integration = (new Integration)->forceFill(['provider' => 'paystack', 'type' => 'payment', 'config' => ['secret_key' => 'sk_live_supersecret']]);
        $integration->save();
        $integration->forceFill(['config' => ['secret_key' => 'sk_live_rotated']])->save();

        $raw = json_encode(AuditLog::query()->pluck('changes'));
        $this->assertStringNotContainsString('sk_live_', $raw);
        $this->assertSame(2, AuditLog::query()->where('auditable_type', $integration->getMorphClass())->count());
    }

    public function test_payment_status_changes_are_recorded_without_the_provider_payload(): void
    {
        $payment = Payment::factory()->create(['status' => 'pending']);

        $payment->update(['status' => 'successful', 'gateway_response' => ['card' => '4084********4081']]);

        $entry = AuditLog::query()->where('action', 'payment.updated')->sole();
        $this->assertSame(['status' => 'successful'], $entry->changes['after']);
    }

    public function test_entries_cannot_be_changed_or_deleted(): void
    {
        $entry = AuditLog::query()->create(['action' => 'test.recorded']);

        try {
            $entry->update(['action' => 'test.tampered']);
            $this->fail('An audit entry was changed.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $entry->delete();
    }

    public function test_the_audit_log_page_needs_its_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->actingAs(User::factory()->create())->get(route('admin.audit-log.index'))->assertForbidden();
        $this->actingAs(User::factory()->create()->givePermissionTo('view_audit_log'))->get(route('admin.audit-log.index'))->assertOk();
    }

    public function test_the_audit_log_page_lists_entries_newest_first_and_filters_by_action(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $auditor = User::factory()->create()->givePermissionTo('view_audit_log');
        $this->actingAs($auditor);
        Setting::store('site.theme', 'dark');
        Payment::factory()->create();

        $this->actingAs($auditor)->get(route('admin.audit-log.index', ['action' => 'setting.']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/AuditLog')
                ->where('filters.action', 'setting.')
                ->has('entries.data', 1)
                ->where('entries.data.0.action', 'setting.created')
                ->where('entries.data.0.actor', $auditor->display_name));
    }

    public function test_admins_get_the_audit_log_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('view_audit_log'));
    }
}
