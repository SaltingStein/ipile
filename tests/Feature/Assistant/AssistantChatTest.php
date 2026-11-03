<?php

namespace Tests\Feature\Assistant;

use App\Ai\Agents\AssistantRequestScreen;
use App\Ai\Agents\SiteAssistant;
use App\Ai\Tools\SearchHelpArticles;
use App\Models\AiInteraction;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\SecurityEvent;
use App\Models\Team;
use App\Models\User;
use App\Services\Assistant\AssistantChat;
use App\Services\Assistant\AssistantIntentRouter;
use App\Services\Assistant\AssistantSettings;
use App\Services\Integrations\IntegrationCatalog;
use App\Services\Integrations\IntegrationManager;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Events\ToolInvoked;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * CORE-911 (chat bubble: streaming, the person's own conversations) and CORE-913 (one append-only log row per request,
 * with a snapshot of what the person was allowed, and the Activity pages).
 */
class AssistantChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        app(IntegrationManager::class)->update(app(IntegrationCatalog::class)->find('openai'), ['is_enabled' => true, 'config' => ['api_key' => 'sk-test', 'model' => 'gpt-test']]);
        app(AssistantSettings::class)->update(true, 'openai', [], 50, staffModes: ['analytics']);
    }

    public function test_the_bubble_streams_a_checked_answer_into_a_new_conversation_and_logs_it(): void
    {
        $this->fake(['Here is how you invite someone. Use this key sk_live_ABCDEFGH12345678 and you are done.']);
        $user = User::factory()->create();

        $content = $this->actingAs($user)->post(route('assistant.stream'), ['message' => 'How do I invite someone?', 'channel' => 'bubble'])
            ->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')->streamedContent();

        $this->assertStringContainsString('event: start', $content);
        $this->assertStringContainsString('event: text', $content);
        $this->assertStringContainsString('event: done', $content);
        $this->assertStringNotContainsString('sk_live_ABCDEFGH12345678', $content);

        $conversation = AssistantConversation::query()->sole();
        $this->assertTrue($conversation->user->is($user));
        $this->assertSame('How do I invite someone?', $conversation->title);
        $this->assertSame([AssistantMessage::ROLE_USER, AssistantMessage::ROLE_ASSISTANT], $conversation->messages->pluck('role')->all());
        $this->assertStringContainsString('[removed]', $conversation->messages[1]->content);

        $interaction = AiInteraction::query()->sole();
        $this->assertSame([AiInteraction::ANSWERED, 'bubble', 'end_user_support', 'openai'], [$interaction->outcome, $interaction->channel, $interaction->mode, $interaction->provider]);
        $this->assertSame($conversation->messages[1]->id, $interaction->assistant_message_id);
        $this->assertSame(['member'], $interaction->roles);
        $this->assertContains('SearchHelpArticles', $interaction->capabilities['tools']);
    }

    public function test_people_only_see_and_continue_their_own_conversations(): void
    {
        $this->fake(['First answer.', 'Second answer.']);
        $user = User::factory()->create();
        $other = AssistantConversation::factory()->create();

        $id = $this->actingAs($user)->postJson(route('assistant.send'), ['message' => 'Hello', 'channel' => 'bubble'])->assertOk()->json('conversation_id');
        $this->postJson(route('assistant.send'), ['message' => 'And then?', 'channel' => 'bubble', 'conversation_id' => $id])->assertOk()->assertJson(['conversation_id' => $id]);

        $this->getJson(route('assistant.conversations'))->assertOk()->assertJsonCount(1, 'conversations')->assertJsonPath('conversations.0.id', $id);
        $this->getJson(route('assistant.conversations.show', $id))->assertOk()->assertJsonCount(4, 'messages');
        $this->getJson(route('assistant.conversations.show', $other->id))->assertNotFound();
        $this->deleteJson(route('assistant.conversations.destroy', $other->id))->assertNotFound();
        $this->postJson(route('assistant.send'), ['message' => 'Hi', 'conversation_id' => $other->id])->assertNotFound();
        $this->assertModelExists($other);

        $this->deleteJson(route('assistant.conversations.destroy', $id))->assertOk();
        $this->assertDatabaseMissing('assistant_conversations', ['id' => $id]);
        $this->assertDatabaseCount('assistant_messages', 0);
    }

    public function test_the_page_keeps_its_conversation_and_starts_afresh_when_cleared(): void
    {
        $this->fake(['One.', 'Two.']);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('assistant.send'), ['message' => 'First'])->assertOk();
        $this->postJson(route('assistant.send'), ['message' => 'Second'])->assertOk();
        $this->get(route('assistant.show'))->assertInertia(fn (Assert $page) => $page->has('history', 4)->where('history.3.html', "<p>Two.</p>\n"));

        $this->deleteJson(route('assistant.clear'))->assertOk();
        $this->get(route('assistant.show'))->assertInertia(fn (Assert $page) => $page->has('history', 0));
        $this->assertSame(1, AssistantConversation::query()->count());
    }

    public function test_the_daily_limit_is_logged_and_repeated_attempts_reach_aegis(): void
    {
        app(AssistantSettings::class)->update(true, 'openai', [], 1);
        $this->fake(['Only answer.']);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('assistant.send'), ['message' => 'Hi', 'channel' => 'bubble'])->assertOk();
        foreach (range(1, AssistantChat::RATE_LIMIT_ALERT_AFTER + 1) as $attempt) {
            $this->post(route('assistant.stream'), ['message' => 'Again', 'channel' => 'bubble'], ['Accept' => 'application/json'])->assertStatus(429);
        }

        $this->assertSame(AssistantChat::RATE_LIMIT_ALERT_AFTER + 1, AiInteraction::query()->where('outcome', AiInteraction::RATE_LIMITED)->count());
        $this->assertSame(1, SecurityEvent::query()->where('type', 'assistant.rate_limited')->where('user_id', $user->id)->count());
    }

    public function test_an_ai_failure_is_logged_and_the_unanswered_message_stays_out_of_the_history(): void
    {
        AssistantRequestScreen::fake(array_fill(0, 2, ['category' => 'none', 'confidence' => 0.9, 'reason' => 'Fine.']));
        SiteAssistant::fake(fn () => throw new RuntimeException('provider down'));
        $user = User::factory()->create();

        $id = $this->actingAs($user)->postJson(route('assistant.send'), ['message' => 'Hello', 'channel' => 'bubble'])->assertStatus(502)->json('conversation_id');

        $interaction = AiInteraction::query()->sole();
        $this->assertSame(AiInteraction::PROVIDER_ERROR, $interaction->outcome);
        $this->assertNotNull($interaction->user_message_id);
        $this->assertSame([], app(AssistantChat::class)->history(AssistantConversation::query()->findOrFail($id)));
    }

    public function test_the_log_snapshots_roles_team_and_tools_with_masked_arguments_and_is_append_only(): void
    {
        $this->fake(['Done.']);
        $user = User::factory()->create()->assignRole('admin');
        $team = Team::factory()->for($user, 'owner')->create();
        $user->forceFill(['current_team_id' => $team->id])->save();
        $chat = app(AssistantChat::class);

        $turn = $chat->begin($user, 'How many sign-ups this month?', null, AssistantIntentRouter::CHANNEL_BUBBLE);
        event(new ToolInvoked('invocation', 'tool', $turn->agent(), new SearchHelpArticles($user), ['query' => 'reset for ada@example.com key sk_live_ABCDEFGH12345678'], 'three articles', 0.1));
        $chat->complete($turn, 'Done.');

        $interaction = AiInteraction::query()->sole();
        $this->assertSame(['admin', 'member'], collect($interaction->roles)->sort()->values()->all());
        $this->assertSame([$team->id, 'owner'], [$interaction->team_id, $interaction->team_role]);
        $this->assertContains('analytics', $interaction->capabilities['modes']);
        $this->assertSame('analytics', $interaction->mode);
        $this->assertSame([['tool' => 'SearchHelpArticles', 'arguments' => ['query' => 'reset for [email] key [removed]'], 'result_bytes' => 14]], $interaction->tools);

        $this->expectException(LogicException::class);
        $interaction->update(['outcome' => AiInteraction::REFUSED]);
    }

    public function test_the_bubble_endpoints_follow_the_assistant_switch_and_permission(): void
    {
        $member = User::factory()->create();
        $this->actingAs(User::factory()->guest()->create())->post(route('assistant.stream'), ['message' => 'Hi'])->assertRedirect(route('register'));

        app(AssistantSettings::class)->update(false, 'openai', [], 50);
        $this->actingAs($member)->postJson(route('assistant.stream'), ['message' => 'Hi'])->assertNotFound();

        app(AssistantSettings::class)->update(true, 'openai', [], 50);
        $member->roles()->detach();
        $member->forgetCachedPermissions();
        $this->actingAs($member->fresh())->postJson(route('assistant.stream'), ['message' => 'Hi'])->assertForbidden();
        $this->getJson(route('assistant.conversations'))->assertForbidden();
        $this->assertDatabaseCount('ai_interactions', 0);
    }

    public function test_activity_pages_need_their_permission_and_show_the_exchange(): void
    {
        $this->fake(['Here is how.']);
        $member = User::factory()->create();
        $this->actingAs($member)->postJson(route('assistant.send'), ['message' => 'How do I invite someone?'])->assertOk();
        $interaction = AiInteraction::query()->sole();

        $this->get(route('admin.assistant.activity.index'))->assertForbidden();
        $this->get(route('admin.assistant.activity.show', $interaction))->assertForbidden();

        $admin = User::factory()->create()->assignRole('admin');
        $this->actingAs($admin)->get(route('admin.assistant.activity.index', ['outcome' => 'answered', 'role' => 'member']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/AssistantActivity')->where('interactions.total', 1));
        $this->get(route('admin.assistant.activity.index', ['outcome' => 'refused']))
            ->assertInertia(fn (Assert $page) => $page->where('interactions.total', 0));
        $this->get(route('admin.assistant.activity.show', $interaction))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/AssistantInteraction')
            ->where('interaction.question', 'How do I invite someone?')
            ->where('interaction.answer_html', "<p>Here is how.</p>\n"));
    }

    public function test_old_chats_and_activity_are_pruned_after_the_retention_period(): void
    {
        $manager = User::factory()->create()->givePermissionTo('manage_settings');
        $this->actingAs($manager)->put(route('admin.assistant.update'), ['enabled' => true, 'provider' => 'openai', 'metrics' => [], 'daily_messages' => 50, 'lockout' => 'suspend', 'activity_retention_days' => 3])
            ->assertSessionHasErrors('activity_retention_days');
        $this->put(route('admin.assistant.update'), ['enabled' => true, 'provider' => 'openai', 'metrics' => [], 'daily_messages' => 50, 'lockout' => 'suspend', 'activity_retention_days' => 30])
            ->assertSessionHasNoErrors();

        $old = AssistantConversation::factory()->create(['updated_at' => now()->subDays(31)]);
        $recent = AssistantConversation::factory()->create();
        $base = ['channel' => 'page', 'roles' => [], 'capabilities' => [], 'tools' => []];
        AiInteraction::query()->create([...$base, 'outcome' => 'answered', 'created_at' => now()->subDays(31)]);
        $kept = AiInteraction::query()->create([...$base, 'outcome' => 'answered', 'created_at' => now()->subDays(29)]);

        $this->artisan('assistant:prune-activity')->expectsOutputToContain('Deleted 1 activity entries and 1 chats')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
        $this->assertSame([$kept->id], AiInteraction::query()->pluck('id')->all());
    }

    /**
     * @param  list<string>  $replies
     */
    private function fake(array $replies): void
    {
        AssistantRequestScreen::fake(array_fill(0, count($replies), ['category' => 'none', 'confidence' => 0.9, 'reason' => 'Fine.']));
        SiteAssistant::fake($replies);
    }
}
