<?php

namespace App\Models;

use Database\Factories\TrustedDeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser that a user chose to trust, allowing it to skip the two-factor challenge until it expires.
 */
class TrustedDevice extends Model
{
    /** @use HasFactory<TrustedDeviceFactory> */
    use HasFactory;

    protected $fillable = [
        'token_hash',
        'ip_address',
        'user_agent',
        'last_used_at',
        'expires_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
