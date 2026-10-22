<?php

namespace App\Services\Ai\Testers;

use App\Services\Ai\AiModelTester;

/**
 * "Test connection" for an OpenAI-compatible server.
 */
class OpenAiCompatibleTester extends AiModelTester
{
    protected function provider(): string
    {
        return 'openai_compatible';
    }
}
