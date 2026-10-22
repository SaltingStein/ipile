<?php

namespace App\Services\Integrations;

/**
 * Describes a provider that can be configured from the dashboard: what it is and which fields it needs.
 */
final readonly class IntegrationDefinition
{
    /**
     * @param  list<IntegrationField>  $fields
     * @param  class-string<IntegrationTester>|null  $tester  Checks the credentials against the provider; null until its driver exists.
     */
    public function __construct(
        public string $provider,
        public IntegrationType $type,
        public string $label,
        public string $description,
        public array $fields,
        public ?string $tester = null,
    ) {}

    public function field(string $name): ?IntegrationField
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }
}
