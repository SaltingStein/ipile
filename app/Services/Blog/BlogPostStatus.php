<?php

namespace App\Services\Blog;

/**
 * Drafts are only visible to blog managers. Published posts appear from their publish time onwards.
 */
enum BlogPostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
        };
    }
}
