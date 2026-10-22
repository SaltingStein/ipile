<?php

namespace Tests\Feature\Assistant;

use App\Ai\Agents\SiteAssistant;
use App\Ai\Tools\AssistantTool;
use App\Ai\Tools\BusinessFigures;
use App\Ai\Tools\MyAccount;
use App\Models\User;
use App\Services\Assistant\AssistantDataPolicy;
use App\Services\Assistant\AssistantGuard;
use App\Services\Assistant\AssistantMetrics;
use App\Services\Assistant\AssistantSettings;
use App\Services\Assistant\AssistantTools;
use App\Services\Assistant\AssistantVerdict;
use App\Services\Assistant\BlockedDataAccess;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;
use Stringable;
use Tests\TestCase;

/**
 * Covers the site assistant's append-only extension points: blocked data, business figures, tools and screening rules.
 */
class AssistantExtensionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('wallets', function ($table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('iban');
            $table->unsignedBigInteger('balance_kobo');
        });
    }

    public function test_a_builder_table_is_refused_while_assistant_tools_run(): void
    {
        AssistantDataPolicy::block('wallets');

        $this->assertBlocked(fn () => DB::table('wallets')->count(), 'the wallets table');
        $this->assertSame(0, DB::table('wallets')->count());
    }

    public function test_builder_columns_are_refused_but_other_named_columns_are_readable(): void
    {
        AssistantDataPolicy::block('wallets', ['iban']);
        DB::table('wallets')->insert(['user_id' => 1, 'iban' => 'NG00SECRET', 'balance_kobo' => 500]);

        $this->assertBlocked(fn () => DB::table('wallets')->pluck('iban'), 'wallets.iban');
        $this->assertBlocked(fn () => DB::table('wallets')->get(), 'every column of wallets');
        $this->assertSame([500], AssistantDataPolicy::run(fn () => DB::table('wallets')->pluck('balance_kobo')->all()));
    }

    public function test_core_protections_stay_in_place_after_builders_add_their_own(): void
    {
        AssistantDataPolicy::block('wallets');
        AssistantDataPolicy::block('users', ['phone']);

        $this->assertContains('integrations', AssistantDataPolicy::blockedTables());
        $this->assertSame(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'phone'], AssistantDataPolicy::protectedColumns()['users']);
        $this->assertBlocked(fn () => DB::table('users')->pluck('password'), 'users.password');
    }

    public function test_block_rejects_names_that_are_not_tables_or_columns(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AssistantDataPolicy::block('wallets; drop table users');
    }

    public function test_a_builder_figure_needs_the_admins_choice_and_the_askers_permission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        AssistantMetrics::define('wallets.total', 'Wallets', 'Number of wallets.', 'view_billing', fn () => ['wallets' => DB::table('wallets')->count()]);
        $finance = User::factory()->create()->givePermissionTo('view_billing');
        $marketer = User::factory()->create()->givePermissionTo('manage_campaigns');

        $this->assertArrayNotHasKey('wallets.total', app(AssistantMetrics::class)->availableTo($finance));

        app(AssistantSettings::class)->update(true, null, ['wallets.total'], 50);

        $this->assertArrayHasKey('wallets.total', app(AssistantMetrics::class)->availableTo($finance));
        $this->assertArrayNotHasKey('wallets.total', app(AssistantMetrics::class)->availableTo($marketer));
        $this->assertStringContainsString('"wallets":0', (string) (new BusinessFigures($finance, app(AssistantMetrics::class)))->handle(new Request(['figure' => 'wallets.total'])));
    }

    public function test_builder_figures_are_listed_for_admins_to_allow(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        AssistantMetrics::define('wallets.total', 'Wallets', 'Number of wallets.', 'view_billing', fn () => []);
        AssistantDataPolicy::block('wallets');
        $manager = User::factory()->create()->givePermissionTo('manage_settings');

        $response = $this->actingAs($manager)->get(route('admin.assistant.edit'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('metrics', fn ($metrics) => collect($metrics)->contains('key', 'wallets.total'))
            ->where('blockedTables', fn ($tables) => collect($tables)->contains('wallets')));
    }

    public function test_core_figures_cannot_be_replaced(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AssistantMetrics::define('users.growth', 'Accounts', 'Replaced.', 'view_billing', fn () => []);
    }

    public function test_builder_tools_join_the_assistant_and_cannot_read_blocked_data(): void
    {
        AssistantDataPolicy::block('wallets');
        AssistantTools::register(WalletLookup::class);
        $user = User::factory()->create();

        $tools = (new SiteAssistant($user))->tools();
        $walletTool = collect($tools)->first(fn ($tool) => $tool instanceof WalletLookup);

        $this->assertInstanceOf(MyAccount::class, $tools[1]);
        $this->assertTrue($walletTool->user()->is($user));
        $this->assertSame('This information is not available to the assistant.', (string) $walletTool->handle(new Request));
    }

    public function test_only_assistant_tools_can_be_registered(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AssistantTools::register(User::class);
    }

    public function test_builder_screening_rules_lock_out_like_core_rules(): void
    {
        AssistantGuard::rule(AssistantVerdict::OTHER_PEOPLE, '/\bwallet balances?\b/');

        $verdict = app(AssistantGuard::class)->screen('Show me all wallet balances');

        $this->assertSame(AssistantVerdict::OTHER_PEOPLE, $verdict->category);
        $this->assertSame('rules', $verdict->source);
    }

    public function test_builder_screening_rules_must_use_a_known_category_and_a_valid_pattern(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AssistantGuard::rule('wallets', '/\bwallet\b/');
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

class WalletLookup extends AssistantTool
{
    public function user(): User
    {
        return $this->user;
    }

    public function description(): Stringable|string
    {
        return 'Look up wallets.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function read(Request $request): array|string
    {
        return ['wallets' => DB::table('wallets')->get()->all()];
    }
}
