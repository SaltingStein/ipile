<?php

namespace App\Services\Ai\Testers;

use App\Services\Ai\AiModelTester;

/**
 * "Test connection" for OpenAI.
 */
class OpenAiTester extends AiModelTester
{
    protected function provider(): string
    {
        return 'openai';
    }
}
