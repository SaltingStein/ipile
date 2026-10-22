<?php

namespace App\Services\Help;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * Help Center articles: one Markdown file per article in resources/help, named after its slug (e.g. two-factor.md).
 *
 * Each file starts with a front matter block of simple `key: value` lines:
 *
 *     ---
 *     title: Two-factor authentication
 *     summary: Protect your account with a code from your phone.
 *     order: 20
 *     permission: view_billing   (optional: only users with this permission see the article)
 *     draft: true                (optional: only shown while the app runs locally)
 *     ---
 */
class HelpCenter
{
    private readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? resource_path('help');
    }

    /**
     * Articles the user may read, in display order. Drafts only appear while the app runs locally.
     *
     * @return Collection<int, HelpArticle>
     */
    public function visibleTo(?User $user): Collection
    {
        return $this->all()
            ->filter(fn (HelpArticle $article) => ! $article->draft || app()->isLocal())
            ->filter(fn (HelpArticle $article) => $article->permission === null || ($user?->can($article->permission) ?? false))
            ->values();
    }

    /**
     * The article with this slug, or null when it does not exist or the user may not read it.
     */
    public function find(string $slug, ?User $user): ?HelpArticle
    {
        return $this->visibleTo($user)->first(fn (HelpArticle $article) => $article->slug === $slug);
    }

    /**
     * @return Collection<int, HelpArticle>
     */
    public function all(): Collection
    {
        if (! File::isDirectory($this->path)) {
            return collect();
        }

        return collect(File::files($this->path))
            ->filter(fn ($file) => $file->getExtension() === 'md' && preg_match('/\A[a-z0-9-]+\z/', $file->getFilenameWithoutExtension()))
            ->map(fn ($file) => $this->parse($file->getFilenameWithoutExtension(), File::get($file->getPathname())))
            ->sortBy([['order', 'asc'], ['title', 'asc']])
            ->values();
    }

    private function parse(string $slug, string $contents): HelpArticle
    {
        $meta = [];
        $body = $contents;

        if (preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $contents, $matches)) {
            $body = $matches[2];

            foreach (preg_split('/\R/', $matches[1]) as $line) {
                if (preg_match('/\A\s*([a-z_]+)\s*:\s*(.*?)\s*\z/', $line, $pair)) {
                    $meta[$pair[1]] = trim($pair[2], '"\'');
                }
            }
        }

        return new HelpArticle(
            slug: $slug,
            title: ($meta['title'] ?? '') !== '' ? $meta['title'] : ucfirst(str_replace('-', ' ', $slug)),
            summary: $meta['summary'] ?? '',
            order: (int) ($meta['order'] ?? 100),
            permission: ($meta['permission'] ?? '') !== '' ? $meta['permission'] : null,
            markdown: trim($body),
            draft: in_array(strtolower($meta['draft'] ?? ''), ['true', 'yes', '1'], true),
        );
    }
}
