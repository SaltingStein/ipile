<?php

namespace App\Services\Campaigns;

use App\Models\Form;
use App\Services\Forms\FormStatus;
use App\Services\Media\VideoEmbed;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The content of a campaign page: a hero and up to {@see MAX_SECTIONS} sections. Builds the validation
 * rules for what the builder sends, and {@see normalise()} keeps only the known keys of each section type, so
 * nothing unexpected is stored or shown.
 *
 * Section types and their fields:
 *
 * | Type        | Fields |
 * |-------------|--------|
 * | `text`      | heading?, body |
 * | `features`  | heading?, items[1–6] {title, text?} |
 * | `image`     | image, alt?, caption? |
 * | `video`     | video_url (YouTube or Vimeo), caption? |
 * | `cta`       | heading, text?, buttons[1–2] |
 * | `form`      | form_id (a published or closed site form), heading?, text? — at most one per page |
 * | `countdown` | heading?, target |
 * | `faq`       | heading?, items[1–12] {question, answer} |
 * | `quote`     | quote, name?, role? |
 * | `stats`     | items[1–4] {value, label} |
 */
class CampaignContent
{
    public const MAX_SECTIONS = 20;

    public const ACCENTS = ['emerald', 'sky', 'violet', 'amber', 'rose', 'slate'];

    public const SECTION_TYPES = ['text', 'features', 'image', 'video', 'cta', 'form', 'countdown', 'faq', 'quote', 'stats'];

    public const IMAGE_DIRECTORY = 'campaigns';

    /**
     * A saved campaign picture: `campaigns/<random name>`, on this server or (with `s3:`) in the bucket.
     */
    public const IMAGE_REFERENCE = '/^(s3:)?campaigns\/[A-Za-z0-9]+\.(jpe?g|png|webp|gif)$/';

    /**
     * Button addresses: web links, site paths, anchors on the page, email and phone links. Never `javascript:`.
     */
    public const BUTTON_URL = '/^(https?:\/\/\S+|\/\S*|#[A-Za-z0-9_-]*|mailto:\S+|tel:\+?[0-9 ()-]+)$/i';

    /**
     * Keys kept for each section type, besides `id` and `type`.
     *
     * @var array<string, list<string>>
     */
    private const SECTION_KEYS = [
        'text' => ['heading', 'body'],
        'features' => ['heading', 'items'],
        'image' => ['image', 'alt', 'caption'],
        'video' => ['video_url', 'caption'],
        'cta' => ['heading', 'text', 'buttons'],
        'form' => ['form_id', 'heading', 'text'],
        'countdown' => ['heading', 'target'],
        'faq' => ['heading', 'items'],
        'quote' => ['quote', 'name', 'role'],
        'stats' => ['items'],
    ];

    public function __construct(private VideoEmbed $videos) {}

    /**
     * Rules for `hero` and `sections`, based on each submitted section's type.
     *
     * @param  array<mixed>  $sections
     * @return array<string, mixed>
     */
    public function rules(array $sections): array
    {
        $rules = [
            'hero' => ['required', 'array'],
            'hero.eyebrow' => ['nullable', 'string', 'max:60'],
            'hero.headline' => ['required', 'string', 'max:150'],
            'hero.text' => ['nullable', 'string', 'max:600'],
            'hero.media' => ['required', Rule::in(['none', 'image', 'video'])],
            'hero.image' => ['nullable', 'required_if:hero.media,image', 'string', 'regex:'.self::IMAGE_REFERENCE],
            'hero.image_alt' => ['nullable', 'string', 'max:200'],
            'hero.video_url' => ['nullable', 'required_if:hero.media,video', 'string', 'max:500'],
            ...$this->buttonRules('hero.buttons', 0),
            'sections' => ['present', 'array', 'max:'.self::MAX_SECTIONS],
            'sections.*' => ['array'],
            'sections.*.id' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', 'distinct'],
            'sections.*.type' => ['required', Rule::in(self::SECTION_TYPES)],
        ];

        foreach (array_values($sections) as $index => $section) {
            $rules = [...$rules, ...$this->sectionRules("sections.{$index}", is_array($section) ? (string) ($section['type'] ?? '') : '')];
        }

        return $rules;
    }

    /**
     * Checks that need more than one field: video links, one form per page, and forms people can use.
     */
    public function after(Validator $validator): void
    {
        $data = $validator->getData();
        $hero = is_array($data['hero'] ?? null) ? $data['hero'] : [];

        if (($hero['media'] ?? null) === 'video' && filled($hero['video_url'] ?? null) && $this->videos->parse((string) $hero['video_url']) === null) {
            $validator->errors()->add('hero.video_url', __('Use a YouTube or Vimeo video link.'));
        }

        $formSections = 0;

        foreach (array_values(is_array($data['sections'] ?? null) ? $data['sections'] : []) as $index => $section) {
            $type = is_array($section) ? ($section['type'] ?? null) : null;

            if ($type === 'video' && filled($section['video_url'] ?? null) && $this->videos->parse((string) $section['video_url']) === null) {
                $validator->errors()->add("sections.{$index}.video_url", __('Use a YouTube or Vimeo video link.'));
            }

            if ($type === 'form' && ++$formSections > 1) {
                $validator->errors()->add("sections.{$index}.form_id", __('A page can have one form. Remove the extra form section.'));
            }
        }
    }

    /**
     * Only the known keys of the hero and each section, with blanks as null.
     *
     * @param  array<string, mixed>  $hero
     * @param  array<mixed>  $sections
     * @return array{hero: array<string, mixed>, sections: list<array<string, mixed>>}
     */
    public function normalise(array $hero, array $sections): array
    {
        $media = in_array($hero['media'] ?? null, ['image', 'video'], true) ? $hero['media'] : 'none';

        return [
            'hero' => [
                'eyebrow' => $this->text($hero['eyebrow'] ?? null),
                'headline' => (string) $this->text($hero['headline'] ?? null),
                'text' => $this->text($hero['text'] ?? null),
                'buttons' => $this->buttons($hero['buttons'] ?? []),
                'media' => $media,
                'image' => $media === 'image' ? $this->text($hero['image'] ?? null) : null,
                'image_alt' => $media === 'image' ? $this->text($hero['image_alt'] ?? null) : null,
                'video_url' => $media === 'video' ? $this->text($hero['video_url'] ?? null) : null,
            ],
            'sections' => array_values(array_map(fn (array $section) => $this->section($section), array_filter($sections, 'is_array'))),
        ];
    }

    /**
     * Every saved picture the content uses, for deleting files that are no longer needed.
     *
     * @param  array<string, mixed>|null  $hero
     * @param  array<mixed>|null  $sections
     * @return list<string>
     */
    public function imageReferences(?array $hero, ?array $sections): array
    {
        $references = [$hero['image'] ?? null];

        foreach ($sections ?? [] as $section) {
            if (is_array($section) && ($section['type'] ?? null) === 'image') {
                $references[] = $section['image'] ?? null;
            }
        }

        return array_values(array_unique(array_filter($references, fn (mixed $reference) => is_string($reference) && preg_match(self::IMAGE_REFERENCE, $reference) === 1)));
    }

    /**
     * The id of the page's form, if it has a form section.
     *
     * @param  array<mixed>|null  $sections
     */
    public function formId(?array $sections): ?int
    {
        foreach ($sections ?? [] as $section) {
            if (is_array($section) && ($section['type'] ?? null) === 'form' && is_numeric($section['form_id'] ?? null)) {
                return (int) $section['form_id'];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionRules(string $prefix, string $type): array
    {
        $optionalHeading = ["{$prefix}.heading" => ['nullable', 'string', 'max:150']];

        return match ($type) {
            'text' => [...$optionalHeading, "{$prefix}.body" => ['required', 'string', 'max:5000']],
            'features' => [
                ...$optionalHeading,
                "{$prefix}.items" => ['required', 'array', 'min:1', 'max:6'],
                "{$prefix}.items.*.title" => ['required', 'string', 'max:80'],
                "{$prefix}.items.*.text" => ['nullable', 'string', 'max:300'],
            ],
            'image' => [
                "{$prefix}.image" => ['required', 'string', 'regex:'.self::IMAGE_REFERENCE],
                "{$prefix}.alt" => ['nullable', 'string', 'max:200'],
                "{$prefix}.caption" => ['nullable', 'string', 'max:200'],
            ],
            'video' => [
                "{$prefix}.video_url" => ['required', 'string', 'max:500'],
                "{$prefix}.caption" => ['nullable', 'string', 'max:200'],
            ],
            'cta' => [
                "{$prefix}.heading" => ['required', 'string', 'max:150'],
                "{$prefix}.text" => ['nullable', 'string', 'max:400'],
                ...$this->buttonRules("{$prefix}.buttons", 1),
            ],
            'form' => [
                ...$optionalHeading,
                "{$prefix}.text" => ['nullable', 'string', 'max:400'],
                // Site forms only (not team forms or the sign-up questions), and not drafts.
                "{$prefix}.form_id" => ['required', 'integer', Rule::exists(Form::class, 'id')->where(fn ($query) => $query
                    ->whereNull('team_id')
                    ->whereNull('purpose')
                    ->where('status', '!=', FormStatus::Draft->value))],
            ],
            'countdown' => [...$optionalHeading, "{$prefix}.target" => ['required', 'date']],
            'faq' => [
                ...$optionalHeading,
                "{$prefix}.items" => ['required', 'array', 'min:1', 'max:12'],
                "{$prefix}.items.*.question" => ['required', 'string', 'max:200'],
                "{$prefix}.items.*.answer" => ['required', 'string', 'max:1000'],
            ],
            'quote' => [
                "{$prefix}.quote" => ['required', 'string', 'max:600'],
                "{$prefix}.name" => ['nullable', 'string', 'max:80'],
                "{$prefix}.role" => ['nullable', 'string', 'max:80'],
            ],
            'stats' => [
                "{$prefix}.items" => ['required', 'array', 'min:1', 'max:4'],
                "{$prefix}.items.*.value" => ['required', 'string', 'max:20'],
                "{$prefix}.items.*.label" => ['required', 'string', 'max:60'],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buttonRules(string $prefix, int $min): array
    {
        return [
            $prefix => [$min > 0 ? 'required' : 'present', 'array', "min:{$min}", 'max:2'],
            "{$prefix}.*.label" => ['required', 'string', 'max:40'],
            "{$prefix}.*.url" => ['required', 'string', 'max:500', 'regex:'.self::BUTTON_URL],
            "{$prefix}.*.style" => ['required', Rule::in(['primary', 'secondary'])],
        ];
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function section(array $section): array
    {
        $type = (string) ($section['type'] ?? '');
        $clean = ['id' => (string) ($section['id'] ?? ''), 'type' => $type];

        foreach (self::SECTION_KEYS[$type] ?? [] as $key) {
            $value = $section[$key] ?? null;

            $clean[$key] = match ($key) {
                'buttons' => $this->buttons($value ?? []),
                'items' => $this->items($type, is_array($value) ? $value : []),
                'form_id' => is_numeric($value) ? (int) $value : null,
                default => $this->text($value),
            };
        }

        return $clean;
    }

    /**
     * @param  array<mixed>  $items
     * @return list<array<string, string|null>>
     */
    private function items(string $type, array $items): array
    {
        $keys = match ($type) {
            'features' => ['title', 'text'],
            'faq' => ['question', 'answer'],
            default => ['value', 'label'],
        };

        return array_values(array_map(
            fn (array $item) => array_combine($keys, array_map(fn (string $key) => $this->text($item[$key] ?? null), $keys)),
            array_filter($items, 'is_array'),
        ));
    }

    /**
     * @return list<array{label: string, url: string, style: string}>
     */
    private function buttons(mixed $buttons): array
    {
        return array_values(array_map(fn (array $button) => [
            'label' => (string) $this->text($button['label'] ?? null),
            'url' => (string) $this->text($button['url'] ?? null),
            'style' => ($button['style'] ?? null) === 'secondary' ? 'secondary' : 'primary',
        ], array_filter(is_array($buttons) ? $buttons : [], 'is_array')));
    }

    private function text(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
