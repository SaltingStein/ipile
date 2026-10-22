<?php

namespace App\Models;

use App\Services\Integrations\IntegrationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Stored settings for one third-party provider, edited from Admin → Integrations.
 * Read values through {@see IntegrationManager}, never directly.
 */
class Integration extends Model
{
    /**
     * Cache of every row's raw attributes, so `config` stays encrypted in the cache store.
     * Cleared by model events: change rows through the model, never with a bulk `query()->update()`.
     */
    public const CACHE_KEY = 'integrations:rows';

    protected $fillable = [
        'provider',
        'type',
        'is_enabled',
    ];

    protected $hidden = [
        'config',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'config' => 'encrypted:array',
        'last_tested_at' => 'datetime',
        'last_test_succeeded' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }
}
