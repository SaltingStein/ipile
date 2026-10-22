<?php

namespace App\Services\Integrations;

/**
 * Checks a provider's credentials (e.g. a cheap authenticated API call or an SMPP bind) without side effects.
 */
interface IntegrationTester
{
    /**
     * @param  array<string, mixed>  $config  The provider's saved field values, decrypted.
     */
    public function test(array $config): IntegrationTestResult;
}
