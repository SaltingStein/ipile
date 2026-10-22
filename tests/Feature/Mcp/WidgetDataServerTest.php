<?php

namespace Tests\Feature\Mcp;

use App\Ai\Tools\AssistantTool;
use App\Mcp\Tools\WidgetDataTool;
use App\Models\SecurityEvent;
use App\Models\Setting;
use App\Models\User;
use App\Services\Assistant\AssistantMetrics;
use App\Services\Assistant\AssistantSettings;
use App\Services\Assistant\AssistantWidget;
use App\Services\Assistant\WidgetDataTools;
use App\Services\Integrations\IntegrationCatalog;
use App\Services\Integrations\IntegrationManager;
use App\Services\Security\SecurityResponder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Stringable;
use Tests\TestCase;

/**
 * Covers the third-party widget's data connection at /mcp/widgets: who may connect, which tools it offers, and that
 * every call is recorded without leaking data or internals.
 */
class WidgetDataServerTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'emb_test_secret_for_widget_signing_123456789';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_the_connection_does_not_exist_unless_data_access_is_on(): void
    {
        $claim = $this->claimFor($this->member());
        $this->mcp('tools/list', claim: $claim)->assertOk();

        $this->enableWidget(['data_access' => 'off']);
        $this->mcp('tools/list', claim: $claim)->assertNotFound();

        $this->enableWidget();
        Setting::store(AssistantSettings::SOURCE, 'built_in');
        $this->mcp('tools/list', claim: $claim)->assertNotFound();
    }

    public function test_other_networks_are_refused_and_recorded(): void
    {
        $claim = $this->claimFor($this->member());
        $this->enableWidget(['data_networks' => '203.0.113.10, 198.51.100.0/24']);

        $this->mcp('tools/list', claim: $claim)->assertForbidden();

        $this->assertSame(['reason' => 'network'], SecurityEvent::query()->where('type', 'mcp.access_refused')->sole()->context);
    }

    public function test_missing_forged_and_expired_claims_are_refused(): void
    {
        $this->freezeTime();
        $claim = $this->claimFor($this->member());
        $payload = (array) JWT::decode($claim, new Key(self::SECRET, 'HS256'));

        $this->mcp('tools/list')->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');
        $this->mcp('tools/list', claim: 'not-a-claim')->assertUnauthorized();
        $this->mcp('tools/list', claim: JWT::encode($payload, str_repeat('x', 40), 'HS256'))->assertUnauthorized();
        // The widget service knows the shared secret, but cannot re-date the encrypted reference.
        $this->mcp('tools/list', claim: JWT::encode(['exp' => $payload['exp'] + 60] + $payload, self::SECRET, 'HS256'))->assertUnauthorized();
        $this->mcp('tools/list', claim: JWT::encode(['ref' => $this->claimFor($this->member(), decode: true)['ref']] + $payload, self::SECRET, 'HS256'))->assertUnauthorized();

        $this->travel(301)->seconds();
        $this->mcp('tools/list', claim: $claim)->assertUnauthorized();

        $this->assertSame(6, SecurityEvent::query()->where('type', 'mcp.access_refused')->count());
    }

    public function test_claims_stop_working_when_the_account_is_signed_out_suspended_or_loses_its_role(): void
    {
        $signedOut = $this->member();
        $claim = $this->claimFor($signedOut);
        app(SecurityResponder::class)->signOutEverywhere($signedOut);
        $this->mcp('tools/list', claim: $claim)->assertUnauthorized();

        $suspended = $this->member();
        $claim = $this->claimFor($suspended);
        $suspended->forceFill(['status' => 'suspended'])->save();
        $this->mcp('tools/list', claim: $claim)->assertUnauthorized();

        $demoted = $this->member();
        $claim = $this->claimFor($demoted);
        $demoted->syncRoles([]);
        $this->mcp('tools/list', claim: $claim)->assertUnauthorized();
    }

    public function test_only_read_only_widget_tools_are_offered(): void
    {
        $tools = $this->mcp('tools/list', claim: $this->claimFor($this->member()))->assertOk()->json('result.tools');

        $this->assertSame(['search-help-articles', 'my-access', 'business-figures'], array_column($tools, 'name'));
        $this->assertSame([true, true, true], array_column(array_column($tools, 'annotations'), 'readOnlyHint'));
    }

    public function test_my_access_returns_roles_and_permissions_without_contact_details_and_is_recorded(): void
    {
        $user = $this->member();
        $user->givePermissionTo('view_billing');

        $response = $this->mcp('tools/call', ['name' => 'my-access', 'arguments' => []], $this->claimFor($user))->assertOk();

        $result = json_decode($response->json('result.content.0.text'), true);
        $this->assertSame(['member'], $result['site_roles']);
        $this->assertContains('view_billing', $result['permissions']);
        $response->assertDontSee($user->email)->assertDontSee($user->name);
        $event = SecurityEvent::query()->where('type', 'mcp.tool_called')->sole();
        $this->assertTrue($event->user->is($user));
        $this->assertSame(['tool' => 'my-access', 'outcome' => 'ok'], $event->context);
    }

    public function test_business_figures_are_limited_to_the_admins_choice_and_the_persons_permission(): void
    {
        AssistantMetrics::define('orders.total', 'Orders', 'Number of orders.', 'view_billing', fn () => ['orders' => 7]);
        app(AssistantSettings::class)->update(false, null, ['orders.total'], 50, source: 'third_party');
        $finance = $this->member();
        $finance->givePermissionTo('view_billing');
        $call = ['name' => 'business-figures', 'arguments' => ['figure' => 'orders.total']];

        $this->mcp('tools/call', $call, $this->claimFor($finance))->assertOk()->assertSee('\"orders\":7', false);
        $this->mcp('tools/call', $call, $this->claimFor($this->member()))->assertOk()->assertSee('not available to this person')->assertDontSee('\"orders\":7', false);
    }

    public function test_blocked_queries_and_errors_reveal_nothing_about_the_data(): void
    {
        config(['app.debug' => true]);
        WidgetDataTools::register(WidgetUserDump::class);
        WidgetDataTools::register(WidgetBrokenTool::class);
        $claim = $this->claimFor($this->member());

        $this->mcp('tools/call', ['name' => 'widget-user-dump', 'arguments' => []], $claim)->assertOk()
            ->assertSee(AssistantTool::NOT_AVAILABLE)->assertDontSee('password');
        $this->mcp('tools/call', ['name' => 'widget-broken-tool', 'arguments' => []], $claim)->assertOk()
            ->assertJsonPath('result.isError', true)->assertSee(WidgetDataTool::FAILED)->assertDontSee('secret_ledger');

        $this->assertSame(['blocked', 'error'], SecurityEvent::query()->where('type', 'mcp.tool_called')->orderBy('id')->get()->pluck('context.outcome')->all());
    }

    public function test_builder_tools_join_the_widget_only_when_registered_for_it(): void
    {
        $this->assertNotContains('widget-user-dump', array_column($this->mcp('tools/list', claim: $this->claimFor($this->member()))->json('result.tools'), 'name'));

        $this->expectException(InvalidArgumentException::class);
        WidgetDataTools::register(User::class);
    }

    public function test_the_claim_carries_an_encrypted_reference_only_for_the_data_connection(): void
    {
        $user = $this->member();

        $claim = $this->claimFor($user, decode: true);

        $this->assertSame(['role', 'visitor', 'exp', 'ref'], array_keys($claim));
        $this->assertStringNotContainsString('"u"', base64_decode($claim['ref']));
        $this->assertSame($user->id, json_decode(Crypt::decryptString($claim['ref']), true)['u']);

        $this->enableWidget(['data_access' => 'off']);
        $this->assertSame(['role', 'visitor', 'exp'], array_keys((array) JWT::decode(app(AssistantWidget::class)->claim($user), new Key(self::SECRET, 'HS256'))));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[TestWith([['access_mode' => 'static', 'shared_secret' => null, 'role_mapping' => null], 'config.data_access'])]
    #[TestWith([['data_networks' => ''], 'config.data_networks'])]
    #[TestWith([['data_networks' => '10.0.0.0/33'], 'config.data_networks'])]
    #[TestWith([['data_networks' => 'example.com'], 'config.data_networks'])]
    #[TestWith([['data_access' => 'sometimes'], 'config.data_access'])]
    public function test_data_access_needs_signed_access_and_valid_networks(array $config, string $error): void
    {
        $manager = User::factory()->create()->givePermissionTo('manage_integrations');

        $this->actingAs($manager)->put(route('admin.integrations.update', AssistantWidget::PROVIDER), [
            'is_enabled' => true, 'config' => array_replace($this->widgetConfig(), $config),
        ])->assertSessionHasErrors($error);
    }

    private function member(): User
    {
        return User::factory()->create()->assignRole('member');
    }

    /**
     * @return ($decode is true ? array<string, mixed> : string)
     */
    private function claimFor(User $user, bool $decode = false): string|array
    {
        if (app(AssistantWidget::class)->dataAccessConfig() === null) {
            $this->enableWidget();
        }

        $claim = app(AssistantWidget::class)->claim($user);

        return $decode ? (array) JWT::decode($claim, new Key(self::SECRET, 'HS256')) : $claim;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function mcp(string $method, array $params = [], ?string $claim = null): TestResponse
    {
        $headers = ['Accept' => 'application/json, text/event-stream'];
        if ($claim !== null) {
            $headers['Authorization'] = 'Bearer '.$claim;
        }

        return $this->postJson('/mcp/widgets', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], $headers);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function enableWidget(array $overrides = []): void
    {
        app(IntegrationManager::class)->update(app(IntegrationCatalog::class)->find(AssistantWidget::PROVIDER), [
            'is_enabled' => true, 'config' => array_replace($this->widgetConfig(), $overrides),
        ]);
        Setting::store(AssistantSettings::SOURCE, 'third_party');
    }

    /**
     * @return array<string, mixed>
     */
    private function widgetConfig(): array
    {
        return [
            'base_url' => 'https://chat.example.com',
            'integration_id' => '3f2a9c1e-7b4d-4e2a-9c8f-1a2b3c4d5e6f',
            'access_mode' => 'signed',
            'shared_secret' => self::SECRET,
            'role_mapping' => '{"admin":"staff","member":"customer"}',
            'claim_seconds' => null,
            'data_access' => 'on',
            'data_networks' => '127.0.0.1, 10.0.0.0/8',
        ];
    }
}

/**
 * A careless builder tool: `select *` on users would include passwords, so the data policy refuses it.
 */
class WidgetUserDump extends AssistantTool
{
    public function description(): Stringable|string
    {
        return 'Every account.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function read(Request $request): array|string
    {
        return DB::table('users')->get()->all();
    }
}

class WidgetBrokenTool extends AssistantTool
{
    public function description(): Stringable|string
    {
        return 'Always fails.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function read(Request $request): array|string
    {
        throw new RuntimeException('SQLSTATE[42S02]: Base table or view not found: secret_ledger');
    }
}
