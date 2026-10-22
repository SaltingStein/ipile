<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Simple key/value settings store for switchable product layouts and global flags.
 * Values edited from the admin dashboard are read through a cache that is cleared on save.
 */
class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Clear the cached value whenever a setting changes.
     */
    protected static function booted(): void
    {
        static::saved(fn (Setting $setting) => Cache::forget(static::cacheKey($setting->key)));
        static::deleted(fn (Setting $setting) => Cache::forget(static::cacheKey($setting->key)));
    }

    /**
     * Read a setting value, falling back to the given default when it has never been saved.
     */
    public static function valueOf(string $key, ?string $default = null): ?string
    {
        return Cache::rememberForever(
            static::cacheKey($key),
            fn () => static::query()->where('key', $key)->value('value'),
        ) ?? $default;
    }

    /**
     * Create or update a setting value.
     */
    public static function store(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * Build the cache key for a setting.
     */
    protected static function cacheKey(string $key): string
    {
        return 'settings:'.$key;
    }
}
