<?php

namespace App\Services\Forms;

/**
 * The kinds of field the form builder offers. Headings only show text; every other type collects an answer.
 */
enum FormFieldType: string
{
    case ShortText = 'text';
    case LongText = 'textarea';
    case Email = 'email';
    case Phone = 'phone';
    case Number = 'number';
    case Date = 'date';
    case Url = 'url';
    case Dropdown = 'select';
    case SingleChoice = 'radio';
    case MultipleChoice = 'checkboxes';
    case Checkbox = 'checkbox';
    case Heading = 'heading';

    public function label(): string
    {
        return match ($this) {
            self::ShortText => 'Short answer',
            self::LongText => 'Paragraph',
            self::Email => 'Email',
            self::Phone => 'Phone number',
            self::Number => 'Number',
            self::Date => 'Date',
            self::Url => 'Website link',
            self::Dropdown => 'Dropdown',
            self::SingleChoice => 'Single choice',
            self::MultipleChoice => 'Multiple choice',
            self::Checkbox => 'Yes / I agree',
            self::Heading => 'Section heading',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ShortText => 'One line of text, such as a company or job title.',
            self::LongText => 'Several lines, for comments or questions.',
            self::Email => 'Checked to be a valid email address.',
            self::Phone => 'Local (0803…) or international (+234…) number.',
            self::Number => 'Whole or decimal number, with optional limits.',
            self::Date => 'A calendar date.',
            self::Url => 'A web address starting with http or https.',
            self::Dropdown => 'Pick one option from a list.',
            self::SingleChoice => 'Pick one option, all shown at once.',
            self::MultipleChoice => 'Tick any number of options.',
            self::Checkbox => 'A single tick box, e.g. accepting a code of conduct.',
            self::Heading => 'A title and text to split the form into sections.',
        };
    }

    /**
     * Whether people answer this field (headings only display text).
     */
    public function collectsInput(): bool
    {
        return $this !== self::Heading;
    }

    /**
     * Whether the field needs a list of options to choose from.
     */
    public function hasOptions(): bool
    {
        return in_array($this, [self::Dropdown, self::SingleChoice, self::MultipleChoice], true);
    }

    /**
     * The palette shown in the builder.
     *
     * @return list<array{value: string, label: string, description: string, has_options: bool, collects_input: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type) => [
            'value' => $type->value,
            'label' => $type->label(),
            'description' => $type->description(),
            'has_options' => $type->hasOptions(),
            'collects_input' => $type->collectsInput(),
        ], self::cases());
    }
}
