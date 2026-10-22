<?php

namespace App\Services\Campaigns;

/**
 * The three designs a campaign page can use. A template sets the layout and styling; the content (hero and
 * sections) is the same for all three, so a page can switch design at any time. New pages start with
 * {@see starter()} content that suits the design.
 */
enum CampaignTemplate: string
{
    case Spotlight = 'spotlight';
    case Split = 'split';
    case Story = 'story';

    public function label(): string
    {
        return match ($this) {
            self::Spotlight => 'Spotlight',
            self::Split => 'Split',
            self::Story => 'Story',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Spotlight => 'A full-width banner or video with a bold headline. Best for launches and big announcements.',
            self::Split => 'Headline and buttons beside a picture, video or sign-up form. Best for registrations and lead capture.',
            self::Story => 'A calm, editorial column for longer copy. Best for causes, stories and detailed offers.',
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $template) => [
            'value' => $template->value,
            'label' => $template->label(),
            'description' => $template->description(),
        ], self::cases());
    }

    /**
     * Starter content for a new page, written as placeholders to replace.
     *
     * @return array{hero: array<string, mixed>, sections: list<array<string, mixed>>}
     */
    public function starter(string $title): array
    {
        $hero = [
            'eyebrow' => null,
            'headline' => $title,
            'text' => 'One or two sentences on what this campaign offers and why it matters.',
            'buttons' => [['label' => 'Get started', 'url' => '#signup', 'style' => 'primary']],
            'media' => 'none',
            'image' => null,
            'image_alt' => null,
            'video_url' => null,
        ];

        return match ($this) {
            self::Spotlight => [
                'hero' => [...$hero, 'eyebrow' => 'Introducing', 'buttons' => [
                    ['label' => 'Get started', 'url' => '#signup', 'style' => 'primary'],
                    ['label' => 'Learn more', 'url' => '#details', 'style' => 'secondary'],
                ]],
                'sections' => [
                    self::section('features', ['heading' => 'Why it is worth it', 'items' => [
                        ['title' => 'First reason', 'text' => 'A short line about the benefit.'],
                        ['title' => 'Second reason', 'text' => 'A short line about the benefit.'],
                        ['title' => 'Third reason', 'text' => 'A short line about the benefit.'],
                    ]]),
                    self::section('stats', ['items' => [['value' => '10k+', 'label' => 'People reached'], ['value' => '24/7', 'label' => 'Support']]]),
                    self::section('cta', ['heading' => 'Ready to join?', 'text' => 'Say what happens next.', 'buttons' => [['label' => 'Get started', 'url' => '#signup', 'style' => 'primary']]]),
                ],
            ],
            self::Split => [
                'hero' => [...$hero, 'buttons' => []],
                'sections' => [
                    self::section('countdown', ['heading' => 'Registration closes in', 'target' => now()->addMonth()->startOfDay()->toIso8601String()]),
                    self::section('features', ['heading' => 'What you get', 'items' => [
                        ['title' => 'First benefit', 'text' => 'A short line about it.'],
                        ['title' => 'Second benefit', 'text' => 'A short line about it.'],
                    ]]),
                    self::section('faq', ['heading' => 'Questions', 'items' => [
                        ['question' => 'Who is this for?', 'answer' => 'Answer the question people ask most.'],
                        ['question' => 'Does it cost anything?', 'answer' => 'Be clear about the price.'],
                    ]]),
                ],
            ],
            self::Story => [
                'hero' => [...$hero, 'eyebrow' => 'Our story'],
                'sections' => [
                    self::section('text', ['heading' => 'Why we are doing this', 'body' => "Tell the story in a few short paragraphs.\n\nKeep each paragraph to one idea."]),
                    self::section('quote', ['quote' => 'A sentence from someone this campaign helps.', 'name' => 'Their name', 'role' => 'Who they are']),
                    self::section('cta', ['heading' => 'Be part of it', 'text' => null, 'buttons' => [['label' => 'Get involved', 'url' => '#signup', 'style' => 'primary']]]),
                ],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function section(string $type, array $attributes): array
    {
        return ['id' => bin2hex(random_bytes(6)), 'type' => $type, ...$attributes];
    }
}
