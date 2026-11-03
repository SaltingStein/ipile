<?php

namespace Tests\Feature;

use App\Ai\Agents\SiteAssistant;
use App\Ai\Insights\ActiveUsers;
use App\Ai\Insights\PaymentBreakdown;
use App\Ai\Insights\SecurityIncidents;
use App\Ai\Insights\SignUpTrend;
use App\Ai\Insights\SmsDelivery;
use App\Ai\Tools\AssistantTool;
use App\Models\Form;
use App\Models\Payment;
use App\Models\SecurityIncident;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Models\Team;
use App\Models\User;
use App\Services\Assistant\AggregateInsight;
use App\Services\Assistant\AssistantDataPolicy;
use App\Services\Assistant\AssistantIntent;
use App\Services\Assistant\AssistantSettings;
use App\Services\Assistant\BlockedDataAccess;
use App\Services\Assistant\InsightRegistry;
use App\Services\Integrations\IntegrationCatalog;
use App\Services\Integrations\IntegrationManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AssistantInsightsTest extends TestCase
{
    use RefreshDatabase;

    private const DENIED = 'That insight is not available to this person. Do not guess it.';

    public function test_admin_sees_discovered_insights_and_they_start_off(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_settings');

        $this->actingAs($manager)->get(route('admin.assistant.edit'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('settings.insights', [])
                ->where('insights', fn ($items): bool => collect($items)->contains('key', 'accounts.signups')));

        $this->assertSame(self::DENIED, (new SignUpTrend($manager))->handle(new Request([])));
    }

    public function test_admin_can_save_known_insights_and_unknown_keys_are_rejected(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_settings');
        $payload = ['enabled' => false, 'provider' => null, 'metrics' => [], 'daily_messages' => 50, 'lockout' => 'suspend'];

        $this->actingAs($manager)->put(route('admin.assistant.update'), [...$payload, 'insights' => ['accounts.signups']])->assertSessionHasNoErrors();
        $this->assertSame(['accounts.signups'], app(AssistantSettings::class)->insights());
        $this->put(route('admin.assistant.update'), [...$payload, 'insights' => ['users.passwords']])->assertSessionHasErrors('insights.0');
        $this->assertSame(['accounts.signups'], app(AssistantSettings::class)->insights());
        $this->actingAs(User::factory()->create())->put(route('admin.assistant.update'), [...$payload, 'insights' => []])->assertForbidden();
    }

    public function test_permission_is_checked_when_a_tool_runs_even_after_it_was_offered(): void
    {
        $actor = $this->actor(['accounts.active'], ['manage_users']);
        $tool = new ActiveUsers($actor);
        $this->assertNotSame(self::DENIED, $tool->handle(new Request([])));
        $actor->fresh()->revokePermissionTo('manage_users');

        $this->assertSame(self::DENIED, $tool->handle(new Request([])));
    }

    public function test_disabling_an_insight_or_the_assistant_revokes_an_existing_tool(): void
    {
        $actor = $this->actor(['accounts.active'], ['manage_users']);
        $tool = new ActiveUsers($actor);
        app(AssistantSettings::class)->updateInsights([]);

        $this->assertSame(self::DENIED, $tool->handle(new Request([])));
        app(AssistantSettings::class)->updateInsights(['accounts.active']);
        Setting::store(AssistantSettings::ENABLED, '0');
        $this->assertSame(self::DENIED, $tool->handle(new Request([])));
    }

    public function test_guests_cannot_use_insights_even_with_permissions(): void
    {
        $this->actor(['accounts.active'], ['manage_users']);
        $guest = User::factory()->guest()->create()->givePermissionTo(['use_assistant_analytics', 'manage_users']);

        $this->assertSame([], app(InsightRegistry::class)->for($guest));
    }

    public function test_insights_are_only_offered_in_the_authorized_staff_analytics_mode(): void
    {
        $actor = $this->actor(['accounts.active'], ['manage_users']);

        $this->assertFalse(collect((new SiteAssistant($actor))->tools())->contains(fn ($tool): bool => $tool instanceof AggregateInsight));
        $this->assertTrue(collect((new SiteAssistant($actor, mode: AssistantIntent::Analytics))->tools())->contains(fn ($tool): bool => $tool instanceof ActiveUsers));

        Setting::store(AssistantSettings::STAFF_MODES, '[]');
        $this->assertSame([], app(InsightRegistry::class)->for($actor));
    }

    public function test_signups_and_active_users_return_only_bounded_period_aggregates(): void
    {
        $this->freezeTime();
        $actor = $this->actor(['accounts.signups', 'accounts.active'], ['manage_users']);
        User::factory()->create(['name' => 'PRIVATE NAME', 'email' => 'private@example.com', 'last_login_at' => now()]);
        User::factory()->guest()->create(['last_login_at' => now()]);
        User::factory()->create(['created_at' => now()->subDays(40), 'last_login_at' => now()->subDays(40)]);

        $signups = (new SignUpTrend($actor))->handle(new Request([]));
        $active = (new ActiveUsers($actor))->handle(new Request([]));

        $this->assertSame(1, json_decode($signups, true)['values'][0]['total']);
        $this->assertSame(1, json_decode($active, true)['values'][0]['total']);
        $this->assertStringNotContainsString('private@example.com', $signups.$active);
        $this->assertStringNotContainsString('PRIVATE NAME', $signups.$active);
    }

    public function test_team_scope_follows_current_team_and_requires_the_team_policy(): void
    {
        $actor = $this->actor(['product.team_forms'], ['manage_forms']);
        $first = Team::factory()->for($actor, 'owner')->create();
        $second = Team::factory()->for($actor, 'owner')->create();
        Form::factory()->for($first)->count(2)->create();
        Form::factory()->for($second)->count(3)->create();
        $actor->forceFill(['current_team_id' => $first->id])->save();
        $tool = new InsightTeamFormsFixture($actor);

        $this->assertSame(2, json_decode($tool->handle(new Request([])), true)['values'][0]['total']);
        $actor->fresh()->forceFill(['current_team_id' => $second->id])->save();
        $this->assertSame(3, json_decode($tool->handle(new Request([])), true)['values'][0]['total']);

        $outsider = Team::factory()->create();
        $actor->forceFill(['current_team_id' => $outsider->id])->save();
        $this->assertSame(self::DENIED, $tool->handle(new Request([])));
        $outsider->users()->attach($actor, ['role' => 'member']);
        $this->assertSame(self::DENIED, $tool->handle(new Request([])));
        $actor->forceFill(['current_team_id' => null])->save();
        $this->assertSame(self::DENIED, $tool->handle(new Request([])));
    }

    public function test_grouped_results_have_a_hard_row_cap(): void
    {
        $actor = $this->actor(['payments.breakdown'], ['view_billing']);
        Payment::factory()->for($actor)->count(51)->sequence(fn (Sequence $sequence): array => [
            'provider' => 'provider_'.$sequence->index, 'currency' => 'NGN', 'amount' => '10.00',
        ])->create();

        $result = json_decode((new PaymentBreakdown($actor))->handle(new Request([])), true);

        $this->assertCount(50, $result['values']);
        $this->assertTrue($result['truncated']);
        $this->assertSame(30, $result['period_days']);
    }

    public function test_scheduled_summaries_are_count_only_idempotent_and_stale_results_are_refused(): void
    {
        $this->freezeTime();
        $actor = $this->actor(['sms.delivery', 'security.incidents'], ['manage_integrations', 'view_security']);
        SmsMessage::factory()->create(['body' => 'PRIVATE SMS BODY', 'to' => '2349999999999']);
        SecurityIncident::factory()->create(['ip_address' => '203.0.113.99', 'resolution_note' => 'PRIVATE INCIDENT NOTE']);

        $this->artisan('assistant:refresh-insights')->expectsOutput('Refreshed 2 aggregate insight summaries.')->assertSuccessful();
        $this->artisan('assistant:refresh-insights')->assertSuccessful();

        $this->assertDatabaseCount('assistant_insight_summaries', 2);
        $sms = (new SmsDelivery($actor))->handle(new Request([]));
        $security = (new SecurityIncidents($actor))->handle(new Request([]));
        $this->assertSame([['status' => 'queued', 'total' => 1]], json_decode($sms, true)['values']);
        $this->assertSame([['status' => 'open', 'total' => 1]], json_decode($security, true)['values']);
        $this->assertStringNotContainsString('PRIVATE', $sms.$security);
        $this->assertStringNotContainsString('203.0.113.99', $sms.$security);
        $this->assertStringNotContainsString('2349999999999', $sms.$security);

        $this->travel(16)->minutes();
        $this->assertFalse(json_decode((new SmsDelivery($actor))->handle(new Request([])), true)['available']);
    }

    public function test_no_summary_is_published_until_an_administrator_opts_in(): void
    {
        $this->artisan('assistant:refresh-insights')->expectsOutput('Refreshed 0 aggregate insight summaries.')->assertSuccessful();

        $this->assertDatabaseCount('assistant_insight_summaries', 0);
    }

    #[TestWith(['security_incidents'])]
    #[TestWith(['sms_messages'])]
    public function test_raw_sms_and_security_tables_stay_blocked(string $table): void
    {
        $this->expectException(BlockedDataAccess::class);

        AssistantDataPolicy::run(fn () => DB::table($table)->count());
    }

    public function test_custom_insights_cannot_read_blocked_tables(): void
    {
        $actor = $this->actor(['product.blocked'], ['manage_settings']);

        $this->assertSame(AssistantTool::NOT_AVAILABLE, (new InsightBlockedFixture($actor))->handle(new Request([])));
    }

    public function test_personal_grouping_fields_are_refused(): void
    {
        $actor = $this->actor(['product.private'], ['manage_users']);
        $this->expectException(InvalidArgumentException::class);

        (new InsightPrivateFixture($actor))->handle(new Request([]));
    }

    public function test_an_insight_cannot_aggregate_a_protected_secret_column(): void
    {
        $actor = $this->actor(['product.secret'], ['manage_users']);

        $this->assertSame(AssistantTool::NOT_AVAILABLE, (new InsightSecretFixture($actor))->handle(new Request([])));
    }

    public function test_a_selected_summary_is_unavailable_until_the_scheduler_refreshes_it(): void
    {
        $actor = $this->actor(['sms.delivery'], ['manage_integrations']);

        $this->assertFalse(json_decode((new SmsDelivery($actor))->handle(new Request([])), true)['available']);
    }

    public function test_generator_rejects_an_invalid_identifier_without_writing_files(): void
    {
        $this->artisan('core:insight', ['name' => '../Escape', '--permission' => 'manage_users'])->assertFailed();
        $this->artisan('core:insight', ['name' => 'InvalidColumnFixture', '--table' => 'users; drop table users', '--permission' => 'manage_users'])->assertFailed();

        $this->assertFileDoesNotExist(app_path('Ai/Insights/InvalidColumnFixture.php'));
    }

    public function test_a_custom_insight_cannot_weaken_the_security_summary_permission(): void
    {
        $actor = $this->actor(['product.security_alias'], ['manage_users']);
        $insight = new class($actor) extends AggregateInsight
        {
            public function key(): string
            {
                return 'product.security_alias';
            }

            public function label(): string
            {
                return 'Security alias';
            }

            public function permission(): ?string
            {
                return 'manage_users';
            }

            public function table(): string
            {
                return 'assistant_insight_summaries';
            }

            public function summaryKey(): ?string
            {
                return 'security.incidents';
            }
        };
        $this->expectException(InvalidArgumentException::class);

        $insight->handle(new Request([]));
    }

    public function test_generator_requires_a_permission_and_does_not_overwrite_existing_files(): void
    {
        $name = 'GeneratedInsightRegressionFixture';
        $source = app_path('Ai/Insights/'.$name.'.php');
        $test = base_path('tests/Feature/'.$name.'InsightTest.php');
        $this->assertFileDoesNotExist($source);
        $this->assertFileDoesNotExist($test);

        $this->artisan('core:insight', ['name' => $name, '--table' => 'forms'])->assertFailed();
        $this->assertFileDoesNotExist($source);

        try {
            $this->artisan('core:insight', ['name' => $name, '--table' => 'forms', '--permission' => 'manage_forms'])->assertSuccessful();
            $this->assertFileExists($test);
            require_once $source;
            $this->assertContains('product.generated_insight_regression_fixture', array_column(app(InsightRegistry::class)->definitions(), 'key'));
            $original = File::get($source);
            $this->artisan('core:insight', ['name' => $name, '--table' => 'users', '--permission' => 'manage_users'])->assertFailed();
            $this->assertSame($original, File::get($source));
        } finally {
            File::delete([$source, $test]);
        }
    }

    public function test_generator_can_explicitly_create_a_safe_insight_without_an_extra_permission(): void
    {
        $name = 'PublicSafeInsightRegressionFixture';
        $source = app_path('Ai/Insights/'.$name.'.php');
        $test = base_path('tests/Feature/'.$name.'InsightTest.php');
        $this->assertFileDoesNotExist($source);
        $this->assertFileDoesNotExist($test);
        $actor = $this->actor(['product.public_safe_insight_regression_fixture'], []);
        Form::factory()->create();

        try {
            $this->artisan('core:insight', ['name' => $name, '--table' => 'forms', '--scope' => 'platform', '--public' => true])->assertSuccessful();
            require_once $source;
            $class = 'App\\Ai\\Insights\\'.$name;
            $tool = new $class($actor);

            $this->assertSame(1, json_decode($tool->handle(new Request([])), true)['values'][0]['total']);
        } finally {
            File::delete([$source, $test]);
        }
    }

    public function test_payment_totals_cover_the_whole_period_without_dropping_statuses(): void
    {
        $actor = $this->actor(['payments.breakdown'], ['view_billing']);

        foreach (range(1, 29) as $daysAgo) {
            foreach (['failed', 'successful'] as $status) {
                Payment::factory()->for($actor)->create(['status' => $status, 'provider' => 'paystack', 'currency' => 'NGN', 'amount' => '10.00', 'created_at' => now()->subDays($daysAgo)]);
            }
        }

        $result = json_decode((new PaymentBreakdown($actor))->handle(new Request([])), true);

        $this->assertFalse($result['truncated']);
        $this->assertSame(['failed', 'successful'], array_column($result['values'], 'status'));
        $this->assertSame([29, 29], array_map('intval', array_column($result['values'], 'total')));
        $this->assertEquals([290, 290], array_map('floatval', array_column($result['values'], 'amount')));
    }

    public function test_team_insights_need_a_team_id_column(): void
    {
        $actor = $this->actor(['product.team_forms'], ['manage_forms']);
        $insight = new class($actor) extends InsightTeamFormsFixture
        {
            public function table(): string
            {
                return 'blog_posts';
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('team_id');

        $insight->handle(new Request([]));
    }

    public function test_generator_defaults_to_the_whole_site_needs_a_table_and_refuses_team_scope_without_team_id(): void
    {
        $name = 'GeneratorDefaultsRegressionFixture';
        $source = app_path('Ai/Insights/'.$name.'.php');
        $test = base_path('tests/Feature/'.$name.'InsightTest.php');

        try {
            $this->artisan('core:insight', ['name' => $name, '--permission' => 'manage_blog'])->assertFailed();
            $this->artisan('core:insight', ['name' => $name, '--table' => 'blog_posts', '--scope' => 'team', '--permission' => 'manage_blog'])->assertFailed();
            $this->assertFileDoesNotExist($source);

            $this->artisan('core:insight', ['name' => $name, '--table' => 'blog_posts', '--permission' => 'manage_blog'])->assertSuccessful();
            $this->assertStringContainsString("return 'platform';", File::get($source));
            $this->assertStringContainsString("'use_assistant_analytics', 'manage_blog'", File::get($test));
            $this->assertStringContainsString('test_allowed_staff_get_aggregates_for_the_period', File::get($test));
        } finally {
            File::delete([$source, $test]);
        }
    }

    public function test_saving_refreshes_summaries_and_insights_removed_from_the_code_are_dropped(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_settings');
        app(IntegrationManager::class)->update(app(IntegrationCatalog::class)->find('openai'), ['is_enabled' => true, 'config' => ['api_key' => 'sk-test']]);
        app(AssistantSettings::class)->update(true, 'openai', [], 50, staffModes: ['analytics']);
        app(AssistantSettings::class)->updateInsights(['accounts.signups', 'product.removed_insight']);

        $this->actingAs($manager)->get(route('admin.assistant.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('settings.insights', ['accounts.signups']));

        $this->put(route('admin.assistant.update'), [
            'enabled' => true, 'provider' => 'openai', 'metrics' => [], 'daily_messages' => 50, 'lockout' => 'suspend',
            'staff_modes' => ['analytics'], 'insights' => ['sms.delivery'],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('assistant_insight_summaries', ['key' => 'sms.delivery']);
    }

    /** @param list<string> $keys @param list<string> $permissions */
    private function actor(array $keys, array $permissions): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        app(AssistantSettings::class)->update(true, 'openai', [], 50, staffModes: ['analytics']);
        app(AssistantSettings::class)->updateInsights($keys);

        return User::factory()->create(['created_at' => now()->subDays(100), 'last_login_at' => null])
            ->givePermissionTo(['use_assistant_analytics', ...$permissions]);
    }
}

class InsightTeamFormsFixture extends AggregateInsight
{
    public function key(): string
    {
        return 'product.team_forms';
    }

    public function label(): string
    {
        return 'Team forms';
    }

    public function permission(): ?string
    {
        return 'manage_forms';
    }

    public function table(): string
    {
        return 'forms';
    }

    public function scope(): string
    {
        return 'team';
    }

    public function teamAbility(): string
    {
        return 'manageForms';
    }
}

class InsightBlockedFixture extends AggregateInsight
{
    public function key(): string
    {
        return 'product.blocked';
    }

    public function label(): string
    {
        return 'Blocked';
    }

    public function permission(): ?string
    {
        return 'manage_settings';
    }

    public function table(): string
    {
        return 'settings';
    }
}

class InsightPrivateFixture extends AggregateInsight
{
    public function key(): string
    {
        return 'product.private';
    }

    public function label(): string
    {
        return 'Private';
    }

    public function permission(): ?string
    {
        return 'manage_users';
    }

    public function table(): string
    {
        return 'users';
    }

    public function groupBy(): array
    {
        return ['email'];
    }
}

class InsightSecretFixture extends AggregateInsight
{
    public function key(): string
    {
        return 'product.secret';
    }

    public function label(): string
    {
        return 'Secret';
    }

    public function permission(): ?string
    {
        return 'manage_users';
    }

    public function table(): string
    {
        return 'users';
    }

    public function sumColumn(): ?string
    {
        return 'password';
    }
}
