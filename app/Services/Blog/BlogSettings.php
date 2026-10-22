<?php

namespace App\Services\Blog;

use App\Models\Setting;

/**
 * The blog's on/off switch, title and introduction, set in Admin → Blog. While the blog is off, its public
 * pages return 404 and the Blog link leaves the site menu; posts can still be written and previewed.
 */
class BlogSettings
{
    public const ENABLED = 'blog.enabled';

    public const TITLE = 'blog.title';

    public const DESCRIPTION = 'blog.description';

    public function enabled(): bool
    {
        return Setting::valueOf(self::ENABLED, '0') === '1';
    }

    public function title(): string
    {
        return Setting::valueOf(self::TITLE, '') ?: 'Blog';
    }

    public function description(): ?string
    {
        return Setting::valueOf(self::DESCRIPTION, '') ?: null;
    }

    /**
     * @return array{enabled: bool, title: string, description: string|null}
     */
    public function all(): array
    {
        return ['enabled' => $this->enabled(), 'title' => $this->title(), 'description' => $this->description()];
    }

    public function update(bool $enabled, string $title, ?string $description): void
    {
        Setting::store(self::ENABLED, $enabled ? '1' : '0');
        Setting::store(self::TITLE, $title);
        Setting::store(self::DESCRIPTION, $description ?? '');
    }
}
