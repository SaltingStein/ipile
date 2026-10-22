<?php

namespace App\Services\Ai\Testers;

use App\Services\Ai\AiModelTester;

/**
 * "Test connection" for OpenRouter.
 */
class OpenRouterTester extends AiModelTester
{
    protected function provider(): string
    {
        return 'openrouter';
    }
}
