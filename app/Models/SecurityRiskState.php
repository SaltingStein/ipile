<?php

namespace App\Models;

use App\Services\Security\Markov\RiskPostureChain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An account's current risk posture: the probability of each level in {@see RiskPostureChain}, and the
 * strongest response already taken in this episode (so the same action is not repeated on every event).
 */
class SecurityRiskState extends Model
{
    protected $fillable = [
        'user_id',
        'belief',
        'score',
        'level',
        'last_action',
        'last_event_at',
    ];

    protected $casts = [
        'belief' => 'array',
        'score' => 'integer',
        'last_event_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
