<?php

namespace App\Services\Ai;

use App\Services\Security\Analysis\AnthropicTester;

/**
 * Details of the Anthropic integration (Admin → Integrations → Anthropic Claude) shared by its settings, its
 * "Test connection" ({@see AnthropicTester}) and {@see AiModels}.
 */
final class AnthropicApi
{
    public const PROVIDER = 'anthropic';

    public const MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'];

    public const API_URL = 'https://api.anthropic.com/v1';

    public const API_VERSION = '2023-06-01';

    /** Sent when the API key is not tied to a workspace (Admin → Integrations → Anthropic Claude → Workspace ID). */
    public const WORKSPACE_HEADER = 'anthropic-workspace-id';

    /**
     * The workspace to use for keys that are not tied to one, or null.
     *
     * @param  array<string, mixed>  $config
     */
    public static function workspaceId(array $config): ?string
    {
        $workspace = trim((string) ($config['workspace_id'] ?? ''));

        return $workspace === '' ? null : $workspace;
    }
}
