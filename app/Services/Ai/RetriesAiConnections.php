<?php

namespace App\Services\Ai;

use Closure;
use Laravel\Ai\Exceptions\ProviderConnectionException;

/**
 * Retries an AI request when the connection to the provider fails (a timeout or dropped connection), which
 * happens on unstable networks. Errors the provider answers with (bad key, bad request) are not retried.
 */
trait RetriesAiConnections
{
    /**
     * @template T
     *
     * @param  Closure(): T  $request
     * @return T
     */
    protected function withConnectionRetries(Closure $request, int $attempts = 3): mixed
    {
        return retry($attempts, $request, fn (int $attempt) => 300 * $attempt, fn (mixed $exception) => $exception instanceof ProviderConnectionException);
    }
}
