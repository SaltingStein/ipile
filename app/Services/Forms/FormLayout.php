<?php

namespace App\Services\Forms;

/**
 * How a form's public page looks. Both layouts can show a banner image or video.
 */
enum FormLayout: string
{
    case Simple = 'simple';
    case Designed = 'designed';

    public function label(): string
    {
        return match ($this) {
            self::Simple => 'Simple',
            self::Designed => 'Designed',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Simple => 'A clean, centred form. A banner or video sits above the title.',
            self::Designed => 'A full-width banner or background video behind the title, with event details beside the form.',
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $layout) => ['value' => $layout->value, 'label' => $layout->label(), 'description' => $layout->description()], self::cases());
    }
}
