<?php

namespace App\Services\Ai\Testers;

use App\Services\Ai\AiModelTester;

/**
 * "Test connection" for Ollama.
 */
class OllamaTester extends AiModelTester
{
    protected function provider(): string
    {
        return 'ollama';
    }
}
