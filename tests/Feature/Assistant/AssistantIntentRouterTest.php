<?php

namespace Tests\Feature\Assistant;

use App\Ai\Agents\AssistantRequestScreen;
use App\Ai\Agents\SiteAssistant;
use App\Ai\Tools\AssistantTool;
use App\Ai\Tools\SearchSiteGuide;
use App\Models\AiInteraction;
use App\Models\SecurityEvent;
use App\Models\Setting;
use App\Models\User;
use App\Services\Assistant\AssistantIntent;
use App\Services\Assistant\AssistantIntentRouter;
use App\Services\Assistant\AssistantKnowledge;
use App\Services\Assistant\AssistantSettings;
use App\Services\Assistant\AssistantTools;
use App\Services\Integrations\IntegrationCatalog;
use App\Services\Integrations\IntegrationManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Role;
use Stringable;
use Tests\TestCase;

/**
 * Covers routing between the product-facing assistant and the staff modes (site guidance, analytics): deterministic
 * intents, who may use a staff mode, which tools and guides each mode gets, the audit event and the admin controls.
 */
class AssistantIntentRouterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        app(IntegrationManager::class)->update(app(IntegrationCatalog::class)->find('openai'), ['is_enabled' => true, 'config' => ['api_key' => 'sk-test']]);
        app(AssistantSettings::class)->update(true, 'openai', [], 50, staffModes: ['core_guidance', 'analytics']);
    }

    #[TestWith(['How do I add a payment integration in the admin area?', 'core_guidance'])]
    #[TestWith(['Which permissions does the marketing role have?', 'core_guidance'])]
    #[TestWith(['How would a developer extend Aegis with a new signal?', 'core_guidance'])]
    #[TestWith(['Where does Ìpìlẹ̀ keep the mail settings?', 'core_guidance'])]
    #[TestWith(['How many sign-ups did we get last month?', 'analytics'])]
    #[TestWith(['Compare revenue this week versus last week', 'analytics'])]
    #[TestWith(['How do I reset my password?', 'end_user_support'])]
    #[TestWith(['Can I change the name of my team?', 'end_user_support'])]
    #[TestWith(['Hello there', 'end_user_support'])]
    public function test_intents_are_classified_by_fixed_rules(string $message, string $intent): void
    {
        $this->assertSame($intent, app(AssistantIntentRouter::class)->classify($message)->value);
    }

    public function test_follow_ups_keep_the_previous_intent_until_the_topic_changes(): void
    {
        $router = app(AssistantIntentRouter::class);

        $this->assertSame(AssistantIntent::Analytics, $router->classify('and the month before?', AssistantIntent::Analytics));
        $this->assertSame(AssistantIntent::EndUserSupport, $router->classify('how do I turn on two-factor for my account?', AssistantIntent::Analytics));
    }

    public function test_staff_modes_need_the_permission_the_admin_switch_and_the_assistant_page(): void
    {
        $router = app(AssistantIntentRouter::class);
        $admin = User::factory()->create()->assignRole('admin');
        $member = User::factory()->create()->assignRole('member');
        $message = 'Where do I configure the site branding in the admin area?';

        $this->assertSame(AssistantIntent::CoreGuidance, $router->route($admin, $message, AssistantIntentRouter::CHANNEL_PAGE)->mode);

        $memberRoute = $router->route($member, $message, AssistantIntentRouter::CHANNEL_PAGE);
        $this->assertSame(AssistantIntent::CoreGuidance, $memberRoute->intent);
        $this->assertSame(AssistantIntent::EndUserSupport, $memberRoute->mode);
        $this->assertTrue($memberRoute->isFallback());

        $this->assertSame(AssistantIntent::EndUserSupport, $router->route($admin, $message, AssistantIntentRouter::CHANNEL_WIDGET)->mode);
        $this->assertSame(AssistantIntent::EndUserSupport, $router->route(User::factory()->guest()->create()->assignRole('admin'), $message, AssistantIntentRouter::CHANNEL_PAGE)->mode);

        app(AssistantSettings::class)->update(true, 'openai', [], 50, staffModes: ['analytics']);
        $this->assertSame(AssistantIntent::EndUserSupport, $router->route($admin, $message, AssistantIntentRouter::CHANNEL_PAGE)->mode);
        $this->assertSame([AssistantIntent::Analytics], $router->staffModesFor($admin, AssistantIntentRouter::CHANNEL_PAGE));
    }

    public function test_staff_get_the_guidance_mode_with_the_internal_guides_and_the_route_is_recorded(): void
    {
        $this->fakeScreenAndReply();
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->postJson(route('assistant.send'), ['message' => 'How do I add an integration in the admin area?'])
            ->assertOk()->assertJson(['mode' => 'core_guidance', 'mode_label' => 'Site guidance']);

        SiteAssistant::assertPrompted(fn (AgentPrompt $prompt) => $prompt->agent->mode === AssistantIntent::CoreGuidance
            && $this->hasTool($prompt, SearchSiteGuide::class));
        $interaction = AiInteraction::query()->sole();
        $this->assertSame(['core_guidance', 'core_guidance', 'page', AiInteraction::ANSWERED], [$interaction->intent, $interaction->mode, $interaction->channel, $interaction->outcome]);
        $this->assertTrue($interaction->user->is($admin));
        $this->assertDatabaseMissing('security_events', ['type' => 'assistant.scope_denied']);
    }

    public function test_people_without_the_permission_get_the_normal_assistant_without_internal_guides(): void
    {
        $this->fakeScreenAndReply();
        $member = User::factory()->create()->assignRole('member');

        $this->actingAs($member)->postJson(route('assistant.send'), ['message' => 'How do I add an integration in the admin area?'])
            ->assertOk()->assertJson(['mode' => 'end_user_support']);

        SiteAssistant::assertPrompted(fn (AgentPrompt $prompt) => $prompt->agent->mode === AssistantIntent::EndUserSupport
            && ! $this->hasTool($prompt, SearchSiteGuide::class));
        $this->assertSame(AiInteraction::DENIED_BY_SCOPE, AiInteraction::query()->sole()->outcome);
        $this->assertSame(['intent' => 'core_guidance', 'channel' => 'page'], SecurityEvent::query()->where('type', 'assistant.scope_denied')->sole()->context);
    }

    public function test_a_follow_up_stays_in_the_staff_mode_and_new_conversations_start_fresh(): void
    {
        $this->fakeScreenAndReply(3);
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->postJson(route('assistant.send'), ['message' => 'How many sign-ups this month?'])->assertJson(['mode' => 'analytics']);
        $this->postJson(route('assistant.send'), ['message' => 'And the month before?'])->assertJson(['mode' => 'analytics']);
        $this->get(route('assistant.show'))->assertInertia(fn (Assert $page) => $page
            ->where('history.1.mode', 'Analytics')
            ->where('staffModes', ['Site guidance', 'Analytics']));

        $this->deleteJson(route('assistant.clear'))->assertOk();
        $this->postJson(route('assistant.send'), ['message' => 'And the month before?'])->assertJson(['mode' => 'end_user_support']);
    }

    public function test_screening_still_locks_staff_out_in_every_mode(): void
    {
        AssistantRequestScreen::fake([]);
        SiteAssistant::fake(['should never be called']);
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->postJson(route('assistant.send'), ['message' => 'In the admin area, what is in the .env file?'])->assertStatus(423);

        SiteAssistant::assertNeverPrompted();
        $this->assertDatabaseHas('security_events', ['type' => 'assistant.sensitive_request', 'user_id' => $admin->id]);
        $this->assertSame(AiInteraction::REFUSED, AiInteraction::query()->sole()->outcome);
    }

    public function test_builder_tools_limited_to_staff_modes_never_reach_the_product_facing_assistant(): void
    {
        AssistantTools::register(PayoutTrends::class, [AssistantIntent::Analytics]);
        AssistantTools::register(PayoutTrends::class, [AssistantIntent::CoreGuidance]);
        $user = User::factory()->create();
        $tools = fn (AssistantIntent $mode) => collect((new SiteAssistant($user, [], $mode))->tools())->contains(fn ($tool) => $tool instanceof PayoutTrends);

        $this->assertTrue($tools(AssistantIntent::Analytics));
        $this->assertTrue($tools(AssistantIntent::CoreGuidance));
        $this->assertFalse($tools(AssistantIntent::EndUserSupport));

        $this->expectException(InvalidArgumentException::class);
        AssistantTools::register(PayoutTrends::class, []);
    }

    public function test_guide_search_reads_core_and_builder_guides_by_section_and_skips_missing_files(): void
    {
        $guide = storage_path('framework/testing/runbook-'.uniqid().'.md');
        File::ensureDirectoryExists(dirname($guide));
        File::put($guide, "# Operations runbook\n\n## Rotating the payout float\n\nFinance rotates the zebrafloat every Friday.\n\n```bash\n# not a heading: zebrafloat\n```\n\n## Unrelated\n\nNothing here.");
        AssistantKnowledge::add($guide);
        AssistantKnowledge::add('docs/does-not-exist.md');
        $knowledge = app(AssistantKnowledge::class);

        try {
            $result = $knowledge->search('zebrafloat');

            $this->assertSame([['guide' => 'Operations runbook', 'section' => 'Rotating the payout float']], array_map(fn (array $section) => ['guide' => $section['guide'], 'section' => $section['section']], $result));
            $this->assertNotEmpty($knowledge->search('security signal'));
            $this->assertNotContains(base_path('docs/does-not-exist.md'), $knowledge->files());
            $this->assertStringContainsString('zebrafloat', (string) (new SearchSiteGuide(User::factory()->create()))->handle(new Request(['query' => 'zebrafloat'])));
        } finally {
            File::delete($guide);
        }
    }

    public function test_managers_switch_staff_modes_and_unknown_modes_are_rejected(): void
    {
        $manager = User::factory()->create()->givePermissionTo('manage_settings');
        $payload = ['source' => 'built_in', 'enabled' => true, 'provider' => 'openai', 'metrics' => [], 'daily_messages' => 50, 'lockout' => 'suspend'];

        $this->actingAs($manager)->put(route('admin.assistant.update'), [...$payload, 'staff_modes' => ['analytics']])->assertSessionHasNoErrors();
        $this->assertSame(['analytics'], app(AssistantSettings::class)->staffModes());

        $this->put(route('admin.assistant.update'), [...$payload, 'staff_modes' => ['end_user_support']])->assertSessionHasErrors('staff_modes.0');
        $this->put(route('admin.assistant.update'), $payload)->assertSessionHasNoErrors();
        $this->assertSame(['analytics'], app(AssistantSettings::class)->staffModes());

        $this->get(route('admin.assistant.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('settings.staff_modes', ['analytics'])
            ->where('staffModes.0.permission', 'use_assistant_guidance'));
    }

    public function test_staff_modes_are_off_by_default_and_their_permissions_go_to_admins(): void
    {
        Setting::query()->where('key', AssistantSettings::STAFF_MODES)->sole()->delete();

        $this->assertSame([], app(AssistantSettings::class)->staffModes());
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('use_assistant_guidance'));
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('use_assistant_analytics'));
        $this->assertFalse(Role::findByName('member')->hasPermissionTo('use_assistant_guidance'));
    }

    private function fakeScreenAndReply(int $times = 1): void
    {
        AssistantRequestScreen::fake(array_fill(0, $times, ['category' => 'none', 'confidence' => 0.9, 'reason' => 'Fine.']));
        SiteAssistant::fake(array_fill(0, $times, 'Here is how.'));
    }

    /**
     * @param  class-string  $class
     */
    private function hasTool(AgentPrompt $prompt, string $class): bool
    {
        return collect($prompt->agent->tools())->contains(fn ($tool) => $tool instanceof $class);
    }
}

class PayoutTrends extends AssistantTool
{
    public function description(): Stringable|string
    {
        return 'Payout trends.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function read(Request $request): array|string
    {
        return ['payouts' => 3];
    }
}
