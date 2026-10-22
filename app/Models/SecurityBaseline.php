<?php

namespace App\Models;

use App\Services\Security\Markov\BaselineTrainer;
use Illuminate\Database\Eloquent\Model;

/**
 * Learned Markov transition counts for one scope: `global`, a role cohort (`role:admin`) or one account (`user:42`).
 * Rebuilt by {@see BaselineTrainer} (`php artisan security:train`); never edited by hand.
 */
class SecurityBaseline extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'scope',
        'transitions',
        'transition_count',
        'trained_at',
    ];

    protected $casts = [
        'transitions' => 'array',
        'transition_count' => 'integer',
        'trained_at' => 'datetime',
    ];
}
