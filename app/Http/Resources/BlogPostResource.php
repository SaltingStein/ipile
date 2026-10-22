<?php

namespace App\Http\Resources;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A blog post for lists and pages. The rendered body is included with `withBody()`; the Markdown source
 * with `forEditor()`.
 *
 * @mixin BlogPost
 */
class BlogPostResource extends JsonResource
{
    private bool $includeBody = false;

    private bool $includeSource = false;

    public function withBody(): static
    {
        $this->includeBody = true;

        return $this;
    }

    public function forEditor(): static
    {
        $this->includeSource = true;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'summary' => $this->summary(),
            'status' => $this->status->value,
            'is_scheduled' => $this->isScheduled(),
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'cover_url' => $this->coverUrl(),
            'reading_minutes' => $this->readingMinutes(),
            'author' => $this->whenLoaded('author', fn () => $this->author === null ? null : ['name' => $this->author->display_name]),
            'categories' => $this->whenLoaded('categories', fn () => $this->categories
                ->map(fn (BlogCategory $category) => ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug])->all()),
            'html' => $this->when($this->includeBody, fn () => $this->html()),
            'excerpt' => $this->when($this->includeSource, fn () => $this->excerpt),
            'body' => $this->when($this->includeSource, fn () => $this->body),
        ];
    }
}
