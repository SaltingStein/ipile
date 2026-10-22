<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\AiModels;
use App\Services\Integrations\IntegrationCatalog;
use App\Services\Integrations\IntegrationManager;
use App\Services\Security\Analysis\AnthropicTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Anthropic keys that are not tied to a workspace must send the workspace ID on every request.
 */
class AnthropicWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_connection_test_sends_the_workspace_and_explains_anthropic_errors(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'This API key is not scoped to a workspace.']], 400)]);

        $withoutWorkspace = app(AnthropicTester::class)->test(['api_key' => 'sk-ant-test']);
        app(AnthropicTester::class)->test(['api_key' => 'sk-ant-test', 'workspace_id' => 'wrkspc_01ABC']);

        $this->assertFalse($withoutWorkspace->succeeded);
        $this->assertSame('The Anthropic API returned HTTP 400: This API key is not scoped to a workspace.', $withoutWorkspace->message);
        Http::assertSent(fn (Request $request) => $request->header('anthropic-workspace-id') === ['wrkspc_01ABC']);
        Http::assertSent(fn (Request $request) => ! $request->hasHeader('anthropic-workspace-id'));
    }

    public function test_workspace_id_is_optional_and_checked(): void
    {
        $definition = app(IntegrationCatalog::class)->find('anthropic');

        $this->assertFalse($definition->field('workspace_id')->required);
        app(IntegrationManager::class)->update($definition, ['is_enabled' => true, 'config' => ['api_key' => 'sk-ant-test', 'model' => 'claude-sonnet-5-5', 'workspace_id' => 'wrkspc_01ABC']]);

        $provider = app(AiModels::class)->provider('anthropic');
        $this->assertSame(['anthropic-workspace-id' => 'wrkspc_01ABC'], $provider->additionalConfiguration()['headers']);
        $this->assertSame(1, preg_match($definition->field('workspace_id')->pattern, 'wrkspc_01ABC'));
        $this->assertSame(0, preg_match($definition->field('workspace_id')->pattern, 'not a workspace'));
    }
}
