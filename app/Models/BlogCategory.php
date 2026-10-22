<?php

namespace App\Models;

use Database\Factories\BlogCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A blog category such as "Events" or "Announcements". Visitors can list a category's posts at
 * /blog/category/{slug}.
 */
class BlogCategory extends Model
{
    /** @use HasFactory<BlogCategoryFactory> */
    use HasFactory;

    public const MAX_CATEGORIES = 50;

    protected $fillable = [
        'name',
        'slug',
        'description',
    ];

    /**
     * @return BelongsToMany<BlogPost, $this>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(BlogPost::class);
    }
}
