<?php

namespace App\Models;

use App\Services\Security\Security;
use Database\Factories\SecurityEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One security-relevant action (sign-in, password change, payment started…) with the risk score Aegis gave it.
 * Written only through {@see Security::record()}; kept for the retention period set in the security centre.
 */
class SecurityEvent extends Model
{
    /** @use HasFactory<SecurityEventFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'security_incident_id',
        'type',
        'category',
        'ip_address',
        'device_hash',
        'network_hash',
        'user_agent',
        'context',
        'risk_score',
        'posture_score',
        'signals',
        'recommended_action',
        'action_taken',
        'occurred_at',
    ];

    protected $hidden = [
        'device_hash',
        'network_hash',
    ];

    protected $casts = [
        'context' => 'array',
        'signals' => 'array',
        'risk_score' => 'integer',
        'posture_score' => 'integer',
        'occurred_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<SecurityIncident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(SecurityIncident::class, 'security_incident_id');
    }
}
