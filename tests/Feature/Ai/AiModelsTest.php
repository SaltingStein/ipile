<?php

namespace Tests\Feature\Ai;

use App\Models\Integration;
use App\Models\User;
use App\Services\Ai\AiModels;
use App\Services\Ai\Testers\GeminiTester;
use App\Services\Ai\Testers\OpenAiCompatibleTester;
use App\Services\Ai\Testers\OpenAiTester;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Tests\TestCase;

use function Laravel\Ai\agent;

class AiModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_model_providers_are_listed_under_ai_models(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $manager = User::factory()->create()->givePermissionTo('manage_integrations');

        $this->actingAs($manager)->get(route('admin.integrations.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('groups', fn ($groups) => collect(collect($groups)->firstWhere('type', 'ai')['providers'])
                ->pluck('provider')->all() === ['anthropic', 'openai', 'gemini', 'openrouter', 'ollama', 'openai_compatible']));
    }

    public function test_only_enabled_providers_are_built(): void
    {
        $this->integration('openai', ['api_key' => 'sk-test'], enabled: false);
        $models = app(AiModels::class);

        $this->assertNull($models->provider('openai'));
        $this->assertNull($models->provider('not-a-provider'));
        $this->assertSame([], $models->enabled());

        Integration::query()->where('provider', 'openai')->sole()->update(['is_enabled' => true]);

        $this->assertNotNull(app(AiModels::class)->provider('openai'));
        $this->assertSame(['openai' => 'OpenAI'], app(AiModels::class)->enabled());
    }

    public function test_prompts_use_the_dashboard_url_key_and_model(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'stop here']], 500)]);
        $this->integration('openai_compatible', ['url' => 'https://llm.example.test/v1/', 'model' => 'local-model', 'api_key' => 'secret-key']);

        try {
            agent('Test')->prompt('Hello', provider: [app(AiModels::class)->provider('openai_compatible')]);
        } catch (\Throwable) {
            // The fake server fails on purpose; only the outgoing request matters here.
        }

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://llm.example.test/v1/')
            && $request->hasHeader('Authorization', 'Bearer secret-key')
            && $request['model'] === 'local-model');
    }

    public function test_connection_test_passes_when_the_model_replies(): void
    {
        AnonymousAgent::fake(['OK']);

        $result = app(OpenAiTester::class)->test(['api_key' => 'sk-test']);

        $this->assertTrue($result->succeeded);
        $this->assertStringStartsWith('The model replied', $result->message);
    }

    public function test_connection_test_explains_a_rejected_key(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Incorrect API key']], 401)]);

        $result = app(OpenAiTester::class)->test(['api_key' => 'sk-wrong']);

        $this->assertFalse($result->succeeded);
        $this->assertSame('The provider rejected the API key.', $result->message);
    }

    public function test_connection_test_explains_an_unknown_model(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'model not found']], 404)]);

        $result = app(OpenAiCompatibleTester::class)->test(['url' => 'https://llm.example.test/v1', 'model' => 'missing']);

        $this->assertFalse($result->succeeded);
        $this->assertStringContainsString('does not know this model or URL', $result->message);
    }

    public function test_connection_test_explains_an_unreachable_server(): void
    {
        // Http::failedConnection() combined with laravel/ai crashes PHP here, so fake the SDK's own error instead.
        AnonymousAgent::fake(fn () => throw ProviderConnectionException::forProvider('ipile-openai_compatible'));

        $result = app(OpenAiCompatibleTester::class)->test(['url' => 'http://localhost:9', 'model' => 'local-model']);

        $this->assertFalse($result->succeeded);
        $this->assertStringContainsString('Could not reach the provider', $result->message);
    }

    public function test_connection_test_retries_a_dropped_connection(): void
    {
        $attempts = 0;
        AnonymousAgent::fake(function () use (&$attempts) {
            if (++$attempts === 1) {
                throw ProviderConnectionException::forProvider('ipile-gemini');
            }

            return 'OK';
        });

        $result = app(GeminiTester::class)->test(['api_key' => 'AIza-test']);

        $this->assertTrue($result->succeeded);
        $this->assertSame(2, $attempts);
    }

    public function test_connection_test_says_when_the_provider_keeps_timing_out(): void
    {
        AnonymousAgent::fake(fn () => throw ProviderConnectionException::forProvider('ipile-gemini', 0, new ConnectionException('cURL error 28: Operation timed out after 30012 milliseconds')));

        $result = app(GeminiTester::class)->test(['api_key' => 'AIza-test']);

        $this->assertFalse($result->succeeded);
        $this->assertStringContainsString('did not answer in time', $result->message);
    }

    /**
     * @param  array<string, string>  $config
     */
    private function integration(string $provider, array $config, bool $enabled = true): void
    {
        $integration = new Integration(['provider' => $provider, 'type' => 'ai', 'is_enabled' => $enabled]);
        $integration->config = $config;
        $integration->save();
    }
}
