<?php

namespace Tests\Feature\Assistant;

use App\Models\Integration;
use App\Models\Setting;
use App\Models\User;
use App\Services\Assistant\AssistantSettings;
use App\Services\Assistant\AssistantWidget;
use App\Services\Integrations\IntegrationCatalog;
use App\Services\Integrations\IntegrationManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AssistantWidgetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Full accounts get the member role, and with it use_ai_assistant, once the roles exist.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private const SECRET = 'emb_test_secret_for_widget_signing_123456789';

    public function test_signed_claim_verifies_with_the_shared_secret_and_contains_only_a_mapped_role_opaque_visitor_and_expiry(): void
    {
        $this->freezeTime();
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create()->assignRole('admin');
        $this->enableWidget();

        $response = $this->actingAs($user)->getJson(route('assistant.widget-claim', ['role' => 'owner', 'visitor' => 'forged', 'exp' => 9999999999]))
            ->assertOk()->assertJsonStructure(['claim']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $payload = (array) JWT::decode($response->json('claim'), new Key(self::SECRET, 'HS256'));

        $this->assertSame(['role', 'visitor', 'exp'], array_keys($payload));
        $this->assertSame('staff', $payload['role']);
        $this->assertSame(now()->timestamp + 300, $payload['exp']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['visitor']);
        $this->assertNotSame((string) $user->id, $payload['visitor']);
        $this->assertStringNotContainsString($user->email, json_encode($payload));
        $this->assertStringNotContainsString($user->display_name, json_encode($payload));

        $anotherClaim = $this->getJson(route('assistant.widget-claim'))->assertOk()->json('claim');
        $this->assertSame($payload['visitor'], JWT::decode($anotherClaim, new Key(self::SECRET, 'HS256'))->visitor);
    }

    public function test_claim_lifetime_can_be_shortened_and_visitors_are_separate_per_account(): void
    {
        $this->freezeTime();
        $this->seed(RolesAndPermissionsSeeder::class);
        $first = User::factory()->create()->assignRole('member');
        $second = User::factory()->create()->assignRole('member');
        $this->enableWidget(['claim_seconds' => 60]);

        $firstClaim = JWT::decode($this->actingAs($first)->getJson(route('assistant.widget-claim'))->assertOk()->json('claim'), new Key(self::SECRET, 'HS256'));
        $secondClaim = JWT::decode($this->actingAs($second)->getJson(route('assistant.widget-claim'))->assertOk()->json('claim'), new Key(self::SECRET, 'HS256'));

        $this->assertSame('customer', $firstClaim->role);
        $this->assertSame(now()->timestamp + 60, $firstClaim->exp);
        $this->assertNotSame($firstClaim->visitor, $secondClaim->visitor);
    }

    public function test_first_matching_global_role_wins_and_super_admin_has_no_implicit_widget_access(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create()->assignRole(['member', 'admin']);
        // Only super_admin: the automatic member role would match the mapping below.
        $superAdmin = User::factory()->create()->syncRoles(['super_admin']);
        $this->enableWidget(['role_mapping' => '{"member":"customer","admin":"staff"}']);

        $claim = $this->actingAs($user)->getJson(route('assistant.widget-claim'))->assertOk()->json('claim');
        $this->assertSame('customer', JWT::decode($claim, new Key(self::SECRET, 'HS256'))->role);
        $this->actingAs($superAdmin)->getJson(route('assistant.widget-claim'))->assertForbidden();
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('assistantWidget', null));
    }

    public function test_anonymous_guest_and_suspended_accounts_cannot_get_claims(): void
    {
        $this->enableWidget();
        $this->getJson(route('assistant.widget-claim'))->assertUnauthorized();
        $this->actingAs(User::factory()->guest()->create())->get(route('assistant.widget-claim'))->assertRedirect(route('register'));
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('assistantWidget', null));
        $this->actingAs(User::factory()->suspended()->create())->getJson(route('assistant.widget-claim'))->assertForbidden();
    }

    #[TestWith(['built_in'])]
    #[TestWith(['off'])]
    public function test_claim_endpoint_and_loader_are_unavailable_for_other_sources(string $source): void
    {
        $user = User::factory()->create();
        $this->enableWidget();
        Setting::store(AssistantSettings::SOURCE, $source);

        $this->actingAs($user)->getJson(route('assistant.widget-claim'))->assertNotFound();
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('assistantWidget', null));
    }

    public function test_disabled_widget_is_not_loaded_and_cannot_issue_claims(): void
    {
        $user = User::factory()->create();
        $this->enableWidget();
        Integration::query()->where('provider', AssistantWidget::PROVIDER)->firstOrFail()->update(['is_enabled' => false]);

        $this->actingAs($user)->getJson(route('assistant.widget-claim'))->assertNotFound();
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('assistantWidget', null));
    }

    public function test_static_widget_needs_no_secret_or_role_mapping_and_has_no_claim_endpoint(): void
    {
        $this->enableWidget(['access_mode' => 'static', 'shared_secret' => null, 'role_mapping' => null]);
        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('assistantEnabled', false)
            ->where('assistantWidget.base_url', 'https://chat.example.com')
            ->where('assistantWidget.claim_endpoint', null));
        $this->getJson(route('assistant.widget-claim'))->assertNotFound();
    }

    public function test_only_public_loader_options_reach_workspace_props_and_credentials_stay_encrypted(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create()->assignRole('member');
        $this->enableWidget();

        $response = $this->actingAs($user)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('assistantEnabled', false)
            ->where('assistantWidget.base_url', 'https://chat.example.com')
            ->where('assistantWidget.integration_id', '3f2a9c1e-7b4d-4e2a-9c8f-1a2b3c4d5e6f')
            ->where('assistantWidget.claim_endpoint', route('assistant.widget-claim'))
            ->missing('assistantWidget.shared_secret')
            ->missing('assistantWidget.role_mapping'));
        $response->assertDontSee(self::SECRET);
        $this->assertStringNotContainsString(self::SECRET, Integration::query()->firstOrFail()->getRawOriginal('config'));
    }

    #[TestWith(['off'])]
    #[TestWith(['third_party'])]
    public function test_switching_source_closes_built_in_page_and_message_routes_even_when_previously_enabled(string $source): void
    {
        $user = User::factory()->create();
        app(AssistantSettings::class)->update(true, 'openai', [], 50, source: $source);

        $this->actingAs($user)->get(route('assistant.show'))->assertNotFound();
        $this->postJson(route('assistant.send'), ['message' => 'Hello'])->assertNotFound();
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('assistantEnabled', false));
    }

    public function test_manager_can_save_widget_or_off_without_a_built_in_ai_provider(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_settings');

        $this->actingAs($manager)->put(route('admin.assistant.update'), [
            'source' => 'third_party', 'enabled' => false, 'provider' => null, 'metrics' => [], 'daily_messages' => 50, 'lockout' => 'suspend',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('settings', ['key' => AssistantSettings::SOURCE, 'value' => 'third_party']);
        $this->get(route('admin.assistant.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('settings.source', 'third_party')->where('widgetSettingsUrl', null));

        $this->put(route('admin.assistant.update'), [
            'source' => 'off', 'enabled' => true, 'provider' => null, 'metrics' => [], 'daily_messages' => 50, 'lockout' => 'suspend',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('settings', ['key' => AssistantSettings::SOURCE, 'value' => 'off']);
    }

    public function test_invalid_source_is_rejected(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_settings');

        $this->actingAs($manager)->put(route('admin.assistant.update'), [
            'source' => 'unknown', 'enabled' => false, 'provider' => null, 'metrics' => [], 'daily_messages' => 50, 'lockout' => 'suspend',
        ])->assertSessionHasErrors('source');
        $this->assertSame('built_in', app(AssistantSettings::class)->source());
    }

    public function test_integration_manager_can_save_the_widget_and_blank_secret_keeps_it_write_only(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_integrations');
        $this->actingAs($manager)->put(route('admin.integrations.update', AssistantWidget::PROVIDER), [
            'is_enabled' => true, 'config' => $this->widgetConfig(),
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('integrations', ['provider' => AssistantWidget::PROVIDER, 'type' => 'assistant', 'is_enabled' => true]);
        $this->put(route('admin.integrations.update', AssistantWidget::PROVIDER), [
            'is_enabled' => true, 'config' => $this->widgetConfig(['shared_secret' => '']),
        ])->assertSessionHasNoErrors();
        $this->assertSame(self::SECRET, app(IntegrationManager::class)->enabledConfig(AssistantWidget::PROVIDER)['shared_secret']);
        $this->get(route('admin.integrations.show', AssistantWidget::PROVIDER))->assertOk()->assertDontSee(self::SECRET);
    }

    #[TestWith(['base_url', 'javascript:alert(1)'])]
    #[TestWith(['base_url', 'https://user:password@chat.example.com'])]
    #[TestWith(['base_url', 'https://chat.example.com/path?key=secret'])]
    #[TestWith(['integration_id', 'not-an-id'])]
    #[TestWith(['access_mode', 'other'])]
    #[TestWith(['shared_secret', ''])]
    #[TestWith(['role_mapping', '[]'])]
    #[TestWith(['role_mapping', '{"member":123}'])]
    #[TestWith(['role_mapping', '{"user":""}'])]
    #[TestWith(['claim_seconds', 301])]
    #[TestWith(['claim_seconds', 0])]
    public function test_invalid_widget_configuration_cannot_be_enabled(string $field, mixed $value): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_integrations');

        $this->actingAs($manager)->put(route('admin.integrations.update', AssistantWidget::PROVIDER), [
            'is_enabled' => true, 'config' => $this->widgetConfig([$field => $value]),
        ])->assertSessionHasErrors('config.'.$field);
        $this->assertDatabaseMissing('integrations', ['provider' => AssistantWidget::PROVIDER]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function enableWidget(array $overrides = []): void
    {
        app(IntegrationManager::class)->update(app(IntegrationCatalog::class)->find(AssistantWidget::PROVIDER), [
            'is_enabled' => true, 'config' => $this->widgetConfig($overrides),
        ]);
        Setting::store(AssistantSettings::SOURCE, 'third_party');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function widgetConfig(array $overrides = []): array
    {
        return array_replace([
            'base_url' => 'https://chat.example.com/',
            'integration_id' => '3f2a9c1e-7b4d-4e2a-9c8f-1a2b3c4d5e6f',
            'access_mode' => 'signed',
            'shared_secret' => self::SECRET,
            'role_mapping' => '{"admin":"staff","member":"customer"}',
            'claim_seconds' => null,
        ], $overrides);
    }
}
