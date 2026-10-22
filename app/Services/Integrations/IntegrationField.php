<?php

namespace App\Services\Integrations;

use Illuminate\Validation\Rule;

/**
 * One setting a provider needs (e.g. "Secret key"). Secret fields are write-only in the dashboard.
 */
final readonly class IntegrationField
{
    /**
     * @param  'text'|'number'|'email'|'select'  $input
     * @param  list<string>  $options  Allowed values for `select` fields.
     * @param  string|null  $pattern  Extra regex a text value must match (e.g. to restrict an API host).
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $input = 'text',
        public bool $required = true,
        public bool $secret = false,
        public array $options = [],
        public ?string $help = null,
        public ?string $pattern = null,
    ) {}

    public static function secret(string $name, string $label, ?string $help = null): self
    {
        return new self($name, $label, required: true, secret: true, help: $help);
    }

    public static function select(string $name, string $label, array $options, ?string $help = null): self
    {
        return new self($name, $label, input: 'select', options: $options, help: $help);
    }

    /**
     * Rules for a submitted value. Blank is always allowed here; "required to enable" is checked separately
     * because a blank secret means "keep the stored value".
     *
     * @return list<mixed>
     */
    public function rules(): array
    {
        return match ($this->input) {
            'number' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'select' => ['nullable', 'string', Rule::in($this->options)],
            default => array_values(array_filter(['nullable', 'string', 'max:1000', $this->pattern === null ? null : 'regex:'.$this->pattern])),
        };
    }

    /**
     * @return array{name: string, label: string, input: string, required: bool, secret: bool, options: list<string>, help: ?string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'input' => $this->secret ? 'password' : $this->input,
            'required' => $this->required,
            'secret' => $this->secret,
            'options' => $this->options,
            'help' => $this->help,
        ];
    }
}
