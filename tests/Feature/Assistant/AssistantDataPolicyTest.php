<?php

namespace Tests\Feature\Assistant;

use App\Ai\Tools\BusinessFigures;
use App\Ai\Tools\MyAccount;
use App\Ai\Tools\MyTeams;
use App\Ai\Tools\SearchHelpArticles;
use App\Models\CampaignPage;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Integration;
use App\Models\Payment;
use App\Models\Team;
use App\Models\User;
use App\Services\Assistant\AssistantDataPolicy;
use App\Services\Assistant\AssistantMetrics;
use App\Services\Assistant\AssistantSettings;
use App\Services\Assistant\BlockedDataAccess;
use App\Services\Integrations\IntegrationCatalog;
use App\Services\Integrations\IntegrationManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

/**
 * Covers AssistantDataPolicy (protected tables and columns are refused before the query runs) and checks that
 * every assistant tool works within it.
 */
class AssistantDataPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_protected_tables_are_refused_before_the_query_runs(): void
    {
        app(IntegrationManager::class)->update(app(IntegrationCatalog::class)->find('paystack'), ['is_enabled' => true, 'config' => ['secret_key' => 'sk_live_secret', 'public_key' => 'pk_live_public']]);

        foreach (['integrations', 'sessions', 'personal_access_tokens', 'password_reset_tokens', 'magic_links', 'trusted_devices', 'settings'] as $table) {
            $this->assertBlocked(fn () => DB::table($table)->get(), $table);
        }

        // Writes are refused too: the row is still there afterwards.
        $this->assertBlocked(fn () => DB::table('integrations')->delete(), 'integrations');
        $this->assertSame(1, Integration::query()->count());
    }

    public function test_password_and_secret_columns_and_whole_rows_are_refused(): void
    {
        User::factory()->withTwoFactor()->create();

        $this->assertBlocked(fn () => User::query()->first(), 'every column of users');
        $this->assertBlocked(fn () => User::query()->first(['id', 'password']), 'users.password');
        $this->assertBlocked(fn () => DB::table('users')->pluck('two_factor_secret'), 'users.two_factor_secret');
        $this->assertBlocked(fn () => DB::table('form_submissions')->pluck('data'), 'form_submissions.data');
        $this->assertBlocked(fn () => Payment::query()->get(), 'every column of payments');

        // Counts and named, harmless columns are fine.
        $this->assertSame(1, AssistantDataPolicy::run(fn () => User::query()->count()));
        $this->assertCount(1, AssistantDataPolicy::run(fn () => User::query()->get(['id', 'name'])));
    }

    public function test_the_policy_only_applies_while_the_assistant_runs(): void
    {
        User::factory()->create();

        $this->assertNotNull(User::query()->first());
        $this->assertFalse(AssistantDataPolicy::isActive());
    }

    public function test_every_tool_works_within_the_policy(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->withTwoFactor()->create(['name' => 'Ada Obi'])->assignRole('super_admin');
        $team = Team::factory()->create(['owner_id' => $user->id, 'name' => 'Summit crew']);
        $team->users()->attach(User::factory()->create(['name' => 'Tunde']), ['role' => 'admin']);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $form = Form::factory()->site()->create();
        FormSubmission::factory()->for($form)->create();
        CampaignPage::factory()->create();
        app(AssistantSettings::class)->update(true, null, array_keys(AssistantMetrics::definitions()), 50);

        $outputs = [
            (new SearchHelpArticles($user))->handle(new Request(['query' => 'two-factor sign in'])),
            (new MyAccount($user))->handle(new Request),
            (new MyTeams($user->fresh()))->handle(new Request),
        ];
        $figures = new BusinessFigures($user, app(AssistantMetrics::class));

        foreach (array_keys(AssistantMetrics::definitions()) as $key) {
            $outputs[] = $figures->handle(new Request(['figure' => $key]));
        }

        foreach ($outputs as $output) {
            $this->assertStringNotContainsString('not available to the assistant', (string) $output);
        }

        $account = json_decode((string) $outputs[1], true);
        $this->assertSame('on', $account['two_factor_sign_in']);
        $this->assertArrayNotHasKey('password', $account);
        $this->assertSame([['name' => 'Tunde', 'role' => 'admin']], json_decode((string) $outputs[2], true)[0]['members']);
        $this->assertStringContainsString('/help/', (string) $outputs[0]);
    }

    public function test_figures_need_both_the_admins_choice_and_the_askers_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        app(AssistantSettings::class)->update(true, null, ['campaigns.performance'], 50);
        $marketer = User::factory()->create()->givePermissionTo(['manage_campaigns', 'manage_users']);
        $member = User::factory()->create();

        $this->assertSame(['campaigns.performance'], array_keys(app(AssistantMetrics::class)->availableTo($marketer)));
        $this->assertSame([], app(AssistantMetrics::class)->availableTo($member));
        $this->assertStringContainsString('not available to this person', (string) (new BusinessFigures($marketer, app(AssistantMetrics::class)))->handle(new Request(['figure' => 'users.growth'])));
    }

    private function assertBlocked(callable $query, string $expected): void
    {
        try {
            AssistantDataPolicy::run($query);
            $this->fail("The query on {$expected} was not refused.");
        } catch (BlockedDataAccess $exception) {
            $this->assertStringContainsString($expected, $exception->getMessage());
        }
    }
}
