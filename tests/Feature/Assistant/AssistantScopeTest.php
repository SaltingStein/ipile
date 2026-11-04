<?php

namespace Tests\Feature\Assistant;

use App\Ai\Agents\SecurityIncidentAnalyst;
use App\Ai\Agents\SiteAssistant;
use App\Ai\Tools\SearchHelpArticles;
use App\Models\User;
use App\Services\Ai\AiModels;
use App\Services\Assistant\AssistantSettings;
use App\Services\Integrations\IntegrationCatalog;
use App\Services\Integrations\IntegrationManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Laravel\Ai\Prompts\AgentPrompt;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * CORE-910: who may use the assistant (use_ai_assistant on the default member role), the App help switch, the
 * administrators' business context, and the dashboard-backed default AI model for agents.
 */
class AssistantScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->enable('openai');
        app(AssistantSettings::class)->update(true, 'openai', [], 50);
    }

    public function test_full_accounts_get_the_member_role_and_with_it_the_assistant(): void
    {
        $member = User::factory()->create();
        $guest = User::factory()->guest()->create();

        $this->assertTrue($member->hasRole('member'));
        $this->assertFalse($guest->hasRole('member'));
        $this->actingAs($member)->get(route('assistant.show'))->assertOk();
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('assistantEnabled', true));

        $guest->forceFill(['is_guest' => false, 'email' => 'upgraded@example.com'])->save();
        $this->assertTrue($guest->fresh()->hasRole('member'));

        $admin = User::factory()->create()->syncRoles(['admin']);
        $this->assertSame(['admin'], $admin->fresh()->getRoleNames()->all());
    }

    public function test_removing_the_permission_from_a_role_takes_the_assistant_away(): void
    {
        Role::findByName('member')->revokePermissionTo('use_ai_assistant');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $member = User::factory()->create();

        $this->actingAs($member)->get(route('assistant.show'))->assertForbidden();
        $this->postJson(route('assistant.send'), ['message' => 'Hello'])->assertForbidden();
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('assistantEnabled', false));
    }

    public function test_existing_full_accounts_without_a_role_get_the_member_role_from_the_migration(): void
    {
        $member = Role::findByName('member');
        $member->revokePermissionTo('use_ai_assistant');
        $roleless = User::factory()->create();
        $roleless->syncRoles([]);
        $guest = User::factory()->guest()->create();

        (require database_path('migrations/2026_10_23_101427_grant_the_site_assistant_to_every_full_account.php'))->up();

        $this->assertTrue($roleless->fresh()->hasRole('member'));
        $this->assertTrue($roleless->fresh()->can('use_ai_assistant'));
        $this->assertFalse($guest->fresh()->hasRole('member'));
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('use_ai_assistant'));
    }

    public function test_app_help_can_be_switched_off(): void
    {
        $user = User::factory()->create();
        $hasHelp = fn () => collect((new SiteAssistant($user))->tools())->contains(fn ($tool) => $tool instanceof SearchHelpArticles);

        $this->assertTrue($hasHelp());

        app(AssistantSettings::class)->updateCapabilities(false, null);

        $this->assertFalse($hasHelp());
        $this->assertStringContainsString('point the person to the Help Center page', (string) (new SiteAssistant($user))->instructions());
    }

    public function test_the_business_context_is_added_after_the_rules_and_validated(): void
    {
        $manager = User::factory()->create()->givePermissionTo('manage_settings');
        $payload = ['enabled' => true, 'provider' => 'openai', 'metrics' => [], 'daily_messages' => 50, 'lockout' => 'suspend'];

        $this->actingAs($manager)->put(route('admin.assistant.update'), [...$payload, 'business_context' => str_repeat('a', AssistantSettings::MAX_BUSINESS_CONTEXT + 1)])
            ->assertSessionHasErrors('business_context');
        $this->put(route('admin.assistant.update'), [...$payload, 'help_enabled' => true, 'business_context' => 'We sell solar kits to farms in Kano.'])
            ->assertSessionHasNoErrors();

        $instructions = (string) (new SiteAssistant($manager))->instructions();
        $this->assertStringContainsString('We sell solar kits to farms in Kano.', $instructions);
        $this->assertGreaterThan(strpos($instructions, 'Rules you never break'), strpos($instructions, 'We sell solar kits'));
        $this->get(route('admin.assistant.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('settings.business_context', 'We sell solar kits to farms in Kano.')
            ->where('settings.help_enabled', true));
    }

    public function test_agents_without_a_provider_use_the_dashboard_model_never_the_env_default(): void
    {
        $this->enable('gemini');
        SecurityIncidentAnalyst::fake([['verdict' => 'suspicious', 'confidence' => 0.5, 'summary' => '', 'explanation' => '', 'recommended_action' => 'monitor', 'suggested_steps' => []]]);

        (new SecurityIncidentAnalyst)->prompt('Briefing');

        SecurityIncidentAnalyst::assertPrompted(fn (AgentPrompt $prompt) => $prompt->provider()->name() === AiModels::DEFAULT_NAME
            && $prompt->provider()->driver() === 'openai');
        $this->assertSame('openai', app(AiModels::class)->defaultProvider());

        app(AssistantSettings::class)->update(true, 'gemini', [], 50);
        $this->assertSame('gemini', app(AiModels::class)->defaultProvider());
    }

    public function test_without_any_ai_model_an_unfaked_agent_fails_clearly(): void
    {
        app(IntegrationManager::class)->update(app(IntegrationCatalog::class)->find('openai'), ['is_enabled' => false]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No AI model is switched on');

        (new SecurityIncidentAnalyst)->prompt('Briefing');
    }

    private function enable(string $provider): void
    {
        app(IntegrationManager::class)->update(app(IntegrationCatalog::class)->find($provider), ['is_enabled' => true, 'config' => ['api_key' => 'sk-test']]);
    }
}
