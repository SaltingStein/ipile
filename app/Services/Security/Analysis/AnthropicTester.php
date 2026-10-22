<?php

namespace App\Services\Security\Analysis;

use App\Services\Integrations\IntegrationTester;
use App\Services\Integrations\IntegrationTestResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * "Test connection" for Anthropic: lists one model, which checks the API key without using any tokens.
 */
class AnthropicTester implements IntegrationTester
{
    public function test(array $config): IntegrationTestResult
    {
        try {
            $response = Http::withHeaders(array_filter([
                'x-api-key' => (string) ($config['api_key'] ?? ''),
                'anthropic-version' => ClaudeSecurityAnalyst::API_VERSION,
                ClaudeSecurityAnalyst::WORKSPACE_HEADER => ClaudeSecurityAnalyst::workspaceId($config),
            ]))->acceptJson()->timeout(15)->get(ClaudeSecurityAnalyst::API_URL.'/models', ['limit' => 1]);
        } catch (ConnectionException) {
            return IntegrationTestResult::failed(__('Could not reach the Anthropic API.'));
        }

        if ($response->status() === 401) {
            return IntegrationTestResult::failed(__('Anthropic rejected the API key.'));
        }

        if ($response->failed()) {
            // Anthropic's error messages explain the problem (e.g. a key with no workspace) and never contain the key.
            $message = trim((string) $response->json('error.message', ''));

            return IntegrationTestResult::failed($message === ''
                ? __('The Anthropic API returned HTTP :status.', ['status' => $response->status()])
                : __('The Anthropic API returned HTTP :status: :message', ['status' => $response->status(), 'message' => Str::limit($message, 300)]));
        }

        return IntegrationTestResult::passed(__('Anthropic accepted the API key.'));
    }
}
