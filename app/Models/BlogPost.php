<?php

namespace App\Models;

use App\Services\Blog\BlogPostStatus;
use App\Services\Media\MediaStorage;
use App\Services\Media\VideoEmbed;
use Database\Factories\BlogPostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * A blog post. The body is Markdown; raw HTML in it is stripped and unsafe links dropped when it is shown.
 * A YouTube or Vimeo link on a line of its own is shown as an embedded player.
 */
class BlogPost extends Model
{
    /** @use HasFactory<BlogPostFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'body',
        'status',
        'published_at',
    ];

    protected $casts = [
        'status' => BlogPostStatus::class,
        'published_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsToMany<BlogCategory, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(BlogCategory::class)->orderBy('name');
    }

    /**
     * Posts visitors can read: published, with a publish time that has arrived.
     *
     * @param  Builder<BlogPost>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('status', BlogPostStatus::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isVisible(): bool
    {
        return $this->status === BlogPostStatus::Published && $this->published_at !== null && ! $this->published_at->isFuture();
    }

    public function isScheduled(): bool
    {
        return $this->status === BlogPostStatus::Published && $this->published_at?->isFuture() === true;
    }

    /**
     * The body as safe HTML.
     */
    public function html(): string
    {
        $html = (string) Str::markdown($this->body, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        // A paragraph holding nothing but a video link (the link text is the address itself) becomes a player.
        return (string) preg_replace_callback('#<p>\s*<a href="([^"]+)">([^<]+)</a>\s*</p>#', function (array $matches): string {
            $href = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
            $video = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5) === $href ? app(VideoEmbed::class)->parse($href) : null;

            return $video === null ? $matches[0] : app(VideoEmbed::class)->iframe($video, filled($this->title) ? __('Video in “:title”', ['title' => $this->title]) : __('Video'));
        }, $html);
    }

    /**
     * The summary shown in lists: the excerpt, or the start of the body as plain text.
     */
    public function summary(): string
    {
        return $this->excerpt ?: Str::limit(trim(strip_tags($this->html())), 200);
    }

    public function readingMinutes(): int
    {
        return max(1, (int) ceil(str_word_count(strip_tags($this->body)) / 200));
    }

    public function coverUrl(): ?string
    {
        return app(MediaStorage::class)->url($this->cover_image_path);
    }
}
