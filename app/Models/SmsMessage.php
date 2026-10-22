<?php

namespace App\Models;

use Database\Factories\SmsMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One outgoing SMS: queued → sent (or failed) → delivered/undelivered when a receipt arrives.
 */
class SmsMessage extends Model
{
    /** @use HasFactory<SmsMessageFactory> */
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_UNDELIVERED = 'undelivered';

    protected $fillable = [
        'user_id',
        'provider',
        'to',
        'sender',
        'body',
        'status',
        'provider_message_id',
        'error',
        'sent_at',
        'delivered_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
