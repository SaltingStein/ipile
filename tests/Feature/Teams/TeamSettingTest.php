<?php

namespace Tests\Feature\Teams;

use App\Models\Setting;
use App\Models\Team;
use App\Models\TeamSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_team_keeps_its_own_value(): void
    {
        [$first, $second] = Team::factory()->count(2)->create();

        $this->assertSame('1', TeamSetting::valueOf($first, 'reports.weekly_summary', '1'));

        TeamSetting::store($first, 'reports.weekly_summary', '0');

        $this->assertSame('0', TeamSetting::valueOf($first, 'reports.weekly_summary', '1'));
        $this->assertSame('1', TeamSetting::valueOf($second, 'reports.weekly_summary', '1'));
    }

    public function test_a_saved_value_replaces_the_cached_one(): void
    {
        $team = Team::factory()->create();
        TeamSetting::store($team, 'receipts.footer', 'Thank you!');
        TeamSetting::valueOf($team, 'receipts.footer');

        TeamSetting::store($team, 'receipts.footer', 'See you soon');

        $this->assertSame('See you soon', TeamSetting::valueOf($team, 'receipts.footer'));
    }

    public function test_resolve_uses_the_team_value_then_the_site_setting_then_the_default(): void
    {
        $team = Team::factory()->create();

        $this->assertSame('default', TeamSetting::resolve($team, 'receipts.footer', 'default'));

        Setting::store('receipts.footer', 'Site footer');
        $this->assertSame('Site footer', TeamSetting::resolve($team, 'receipts.footer', 'default'));

        TeamSetting::store($team, 'receipts.footer', 'Team footer');
        $this->assertSame('Team footer', TeamSetting::resolve($team, 'receipts.footer', 'default'));

        TeamSetting::forget($team, 'receipts.footer');
        $this->assertSame('Site footer', TeamSetting::resolve($team, 'receipts.footer', 'default'));
    }

    public function test_team_secrets_are_encrypted_at_rest(): void
    {
        $team = Team::factory()->create();

        TeamSetting::storeSecret($team, 'payments.secret_key', 'sk_live_team');

        $this->assertSame('sk_live_team', TeamSetting::secretOf($team, 'payments.secret_key'));
        $this->assertDatabaseMissing('team_settings', ['value' => 'sk_live_team']);
    }
}
