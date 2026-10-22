<?php

namespace App\Services\Help;

use Illuminate\Support\Str;

/**
 * One Help Center article, read from a Markdown file in resources/help.
 */
final readonly class HelpArticle
{
    public function __construct(
        public string $slug,
        public string $title,
        public string $summary,
        public int $order,
        public ?string $permission,
        public string $markdown,
        public bool $draft = false,
    ) {}

    /**
     * The article body as HTML. Raw HTML in the Markdown is stripped and unsafe links are dropped.
     */
    public function html(): string
    {
        return (string) Str::markdown($this->markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * @return array{slug: string, title: string, summary: string}
     */
    public function summaryDetails(): array
    {
        return ['slug' => $this->slug, 'title' => $this->title, 'summary' => $this->summary];
    }
}
