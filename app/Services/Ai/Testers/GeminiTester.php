<?php

namespace App\Services\Ai\Testers;

use App\Services\Ai\AiModelTester;

/**
 * "Test connection" for Google Gemini.
 */
class GeminiTester extends AiModelTester
{
    protected function provider(): string
    {
        return 'gemini';
    }
}
