<?php

namespace App\Models;

use Database\Factories\TeamSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Key/value settings that each team chooses for itself, the per-team counterpart of {@see Setting}:
 *
 *     TeamSetting::valueOf($team, 'reports.weekly_summary', '1');
 *     TeamSetting::store($team, 'reports.weekly_summary', '0');
 *     TeamSetting::resolve($team, 'receipts.footer');   // the team's value, else the site-wide Setting, else the default
 *
 * Values are strings, read through a cache that is cleared on save. Team secrets (a team's own API key) use
 * {@see storeSecret()} and {@see secretOf()}: stored encrypted, and never to be sent to the browser.
 */
class TeamSetting extends Model
{
    /** @use HasFactory<TeamSettingFactory> */
    use HasFactory;

    protected $fillable = [
        'team_id',
        'key',
        'value',
    ];

    protected static function booted(): void
    {
        static::saved(fn (TeamSetting $setting) => Cache::forget(static::cacheKey($setting->team_id, $setting->key)));
        static::deleted(fn (TeamSetting $setting) => Cache::forget(static::cacheKey($setting->team_id, $setting->key)));
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The team's value, or the default when the team has never saved one.
     */
    public static function valueOf(Team $team, string $key, ?string $default = null): ?string
    {
        return Cache::rememberForever(
            static::cacheKey($team->getKey(), $key),
            fn () => static::query()->where('team_id', $team->getKey())->where('key', $key)->value('value'),
        ) ?? $default;
    }

    /**
     * The team's value, else the site-wide {@see Setting} with the same key, else the default.
     */
    public static function resolve(Team $team, string $key, ?string $default = null): ?string
    {
        return static::valueOf($team, $key) ?? Setting::valueOf($key, $default);
    }

    public static function store(Team $team, string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['team_id' => $team->getKey(), 'key' => $key], ['value' => $value]);
    }

    /**
     * Remove the team's value, so {@see resolve()} falls back to the site-wide setting again.
     */
    public static function forget(Team $team, string $key): void
    {
        static::query()->where('team_id', $team->getKey())->where('key', $key)->first()?->delete();
    }

    /**
     * Store a team secret encrypted. Pass null to remove it.
     */
    public static function storeSecret(Team $team, string $key, ?string $value): void
    {
        static::store($team, $key, $value === null ? null : Crypt::encryptString($value));
    }

    /**
     * A team secret saved with {@see storeSecret()}, decrypted. Server-side use only.
     */
    public static function secretOf(Team $team, string $key): ?string
    {
        $encrypted = static::valueOf($team, $key);

        return $encrypted === null ? null : Crypt::decryptString($encrypted);
    }

    protected static function cacheKey(int|string $teamId, string $key): string
    {
        return "team_settings:{$teamId}:{$key}";
    }
}
