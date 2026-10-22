<?php

namespace App\Services\Blog;

use App\Models\BlogPost;
use App\Services\Media\MediaStorage;

/**
 * Files that belong to blog posts: cover images and the pictures placed in post bodies (saved under
 * `blog/content`, on this server or in the storage bucket; see {@see MediaStorage}). Body pictures are deleted
 * once no post refers to them any more, so a picture copied from one post into another is kept while either
 * post still shows it.
 */
class BlogAssets
{
    public const COVER_DIRECTORY = 'blog';

    public const CONTENT_DIRECTORY = 'blog/content';

    public function __construct(private MediaStorage $storage) {}

    /**
     * References of the body pictures a post's Markdown links to.
     *
     * @return list<string>
     */
    public function contentImages(?string $body): array
    {
        preg_match_all('/!\[[^\]]*\]\(\s*<?([^)\s>]+)/', (string) $body, $matches);

        $references = [];

        foreach ($matches[1] as $url) {
            $reference = $this->storage->referenceFromUrl($url);
            $path = $reference === null ? '' : preg_replace('/^'.preg_quote(MediaStorage::BUCKET_PREFIX, '/').'/', '', $reference);

            if ($reference !== null && str_starts_with($path, self::CONTENT_DIRECTORY.'/') && ! str_contains($path, '..')) {
                $references[] = $reference;
            }
        }

        return array_values(array_unique($references));
    }

    /**
     * After a post's body changes: delete pictures the old body used that neither the new body nor any
     * other post uses.
     */
    public function pruneRemovedImages(BlogPost $post, ?string $oldBody): void
    {
        $this->deleteUnused(array_diff($this->contentImages($oldBody), $this->contentImages($post->body)), $post);
    }

    /**
     * Delete a post's cover and every body picture no other post uses. Call after the post is deleted.
     */
    public function deleteFor(BlogPost $post): void
    {
        if ($post->cover_image_path !== null) {
            $this->storage->delete($post->cover_image_path);
        }

        $this->deleteUnused($this->contentImages($post->body), $post);
    }

    /**
     * Delete body pictures that were uploaded but never saved in a post (for example when the editor was
     * closed without saving). Pictures younger than `$olderThanHours` are kept, as someone may still be writing.
     *
     * @return int The number of files deleted.
     */
    public function pruneOrphans(int $olderThanHours = 24): int
    {
        $cutoff = now()->subHours($olderThanHours)->getTimestamp();

        $orphans = array_keys(array_filter($this->storage->filesIn(self::CONTENT_DIRECTORY), fn (int $modified, string $reference) => $modified < $cutoff
            && ! $this->isUsedByAnotherPost($reference), ARRAY_FILTER_USE_BOTH));

        if ($orphans !== []) {
            $this->storage->delete($orphans);
        }

        return count($orphans);
    }

    /**
     * @param  array<string>  $references
     */
    private function deleteUnused(array $references, BlogPost $post): void
    {
        $unused = array_values(array_filter($references, fn (string $reference) => ! $this->isUsedByAnotherPost($reference, $post)));

        if ($unused !== []) {
            $this->storage->delete($unused);
        }
    }

    /**
     * Uploaded file names are random, so a post that contains the name links to that file.
     */
    private function isUsedByAnotherPost(string $reference, ?BlogPost $except = null): bool
    {
        return BlogPost::query()
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->where('body', 'like', '%'.basename($reference).'%')
            ->exists();
    }
}
