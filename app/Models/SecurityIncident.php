<?php

namespace App\Models;

use Database\Factories\SecurityIncidentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A run of risky events for one account (or one IP address when nobody is signed in) that an administrator
 * should look at. Events close together are grouped into the same open incident.
 */
class SecurityIncident extends Model
{
    /** @use HasFactory<SecurityIncidentFactory> */
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_RESOLVED = 'resolved';

    /** Resolutions an administrator can choose. `confirmed` events are left out of baseline training. */
    public const RESOLUTIONS = ['benign', 'false_positive', 'confirmed'];

    public const AI_PENDING = 'pending';

    public const AI_COMPLETED = 'completed';

    public const AI_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'ip_address',
        'status',
        'resolution',
        'risk_score',
        'risk_level',
        'recommended_action',
        'action_taken',
        'event_count',
        'first_event_at',
        'last_event_at',
        'resolution_note',
    ];

    protected $casts = [
        'risk_score' => 'integer',
        'event_count' => 'integer',
        'first_event_at' => 'datetime',
        'last_event_at' => 'datetime',
        'ai_analysis' => 'array',
        'ai_analyzed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /**
     * Whether the incident still needs attention.
     */
    public function isActive(): bool
    {
        return $this->status !== self::STATUS_RESOLVED;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @return HasMany<SecurityEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SecurityEvent::class);
    }
}
