<?php

namespace App\Services\Integrations;

/**
 * Outcome of "Test connection". `succeeded` is null when the provider has no connection test yet.
 */
final readonly class IntegrationTestResult
{
    public function __construct(
        public ?bool $succeeded,
        public string $message,
    ) {}

    public static function passed(string $message): self
    {
        return new self(true, $message);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }

    public static function unavailable(string $message): self
    {
        return new self(null, $message);
    }
}
