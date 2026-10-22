<?php

namespace App\Models;

use Database\Factories\TranslationStringFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A piece of text seen on a public page, in the site's own language. See App\Services\Translation\StringCollector.
 */
class TranslationString extends Model
{
    /** @use HasFactory<TranslationStringFactory> */
    use HasFactory;

    protected $fillable = [
        'hash',
        'text',
        'reporter_hash',
        'confirmed_at',
        'last_seen_at',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /**
     * @return HasMany<Translation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(Translation::class);
    }

    public static function hashOf(string $text): string
    {
        return hash('sha256', $text);
    }
}
