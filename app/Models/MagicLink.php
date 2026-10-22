<?php

namespace App\Models;

use Database\Factories\MagicLinkFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MagicLink extends Model
{
    /** @use HasFactory<MagicLinkFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'token', 'email', 'expires_at', 'used_at', 'delivery_started_at'];

    protected $hidden = ['token', 'email'];

    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime', 'delivery_started_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query->whereNull('used_at')->where('expires_at', '>', now());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', now());
    }
}
