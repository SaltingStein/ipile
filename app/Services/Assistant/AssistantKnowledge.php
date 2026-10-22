<?php

namespace App\Services\Assistant;

use App\Ai\Tools\SearchSiteGuide;
use Illuminate\Support\Str;

/**
 * The internal guides the staff guidance mode answers from ({@see SearchSiteGuide}): Ìpìlẹ̀'s developer guide, dev
 * notes, extension guides and skills, plus any Markdown files a builder adds. Only these files are ever read; the
 * model chooses search words, never paths. Files that do not exist (for example when `project-docs` is not deployed)
 * are skipped.
 *
 *     AssistantKnowledge::add('docs/runbook.md');      // relative to the project root, or an absolute path
 *     AssistantKnowledge::add('docs/handbook/*.md');   // globs work too
 *
 * Never shown to the product-facing assistant or the third-party widget.
 *
 * @api
 */
class AssistantKnowledge
{
    /**
     * @var list<string>
     */
    public const BUILT_IN = [
        'project-docs/developer-guide.md',
        'project-docs/dev-notes.md',
        'project-docs/aegis-extension-guide.md',
        'project-docs/widget-data-connection.md',
        '.ai/skills/*/SKILL.md',
    ];

    public const SECTION_CHARACTERS = 2500;

    public function __construct(private AssistantExtensions $extensions) {}

    /**
     * Call it in a service provider's `boot()`. Append-only.
     */
    public static function add(string $path): void
    {
        app(AssistantExtensions::class)->addKnowledge($path);
    }

    /**
     * The guide files that exist now.
     *
     * @return list<string>
     */
    public function files(): array
    {
        $files = [];

        foreach ([...self::BUILT_IN, ...$this->extensions->knowledge()] as $source) {
            $pattern = preg_match('#^([a-zA-Z]:)?[\\\\/]#', $source) === 1 ? $source : base_path($source);

            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && is_readable($file) && Str::endsWith(Str::lower($file), '.md')) {
                    $files[] = realpath($file) ?: $file;
                }
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * The sections that best match the words, best first.
     *
     * @return list<array{guide: string, section: string, text: string}>
     */
    public function search(string $query, int $limit = 3): array
    {
        $words = array_values(array_filter(preg_split('/\W+/u', Str::lower($query)) ?: [], fn (string $word) => mb_strlen($word) > 2));

        if ($words === []) {
            return [];
        }

        return collect($this->files())
            ->flatMap(fn (string $file) => $this->sections($file))
            ->map(function (array $section) use ($words) {
                $heading = Str::lower($section['guide'].' '.$section['section']);
                $body = Str::lower($section['text']);
                $section['score'] = array_sum(array_map(fn (string $word) => substr_count($heading, $word) * 3 + substr_count($body, $word), $words));

                return $section;
            })
            ->filter(fn (array $section) => $section['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->map(fn (array $section) => [
                'guide' => $section['guide'],
                'section' => $section['section'],
                'text' => Str::limit($section['text'], self::SECTION_CHARACTERS),
            ])
            ->values()
            ->all();
    }

    /**
     * A file split at its Markdown headings, without front matter.
     *
     * @return list<array{guide: string, section: string, text: string}>
     */
    private function sections(string $file): array
    {
        $markdown = (string) preg_replace('/\A---\R.*?\R---\R/s', '', (string) file_get_contents($file));
        $guide = preg_match('/\A(?:\s*\R)*#\s+(.+)$/m', $markdown, $title) === 1 ? trim($title[1]) : Str::headline(pathinfo($file, PATHINFO_FILENAME));
        $sections = [];
        $heading = $guide;
        $lines = [];
        $inCode = false;

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                $inCode = ! $inCode;
            }

            // Shell comments inside code blocks start with "#" too.
            if (! $inCode && preg_match('/^#{1,4}\s+(.+)$/', $line, $match) === 1) {
                $sections[] = ['guide' => $guide, 'section' => $heading, 'text' => trim(implode("\n", $lines))];
                $heading = trim($match[1]);
                $lines = [];

                continue;
            }

            $lines[] = $line;
        }

        $sections[] = ['guide' => $guide, 'section' => $heading, 'text' => trim(implode("\n", $lines))];

        return array_values(array_filter($sections, fn (array $section) => $section['text'] !== ''));
    }
}
