<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * OAuth client credentials for a social login provider, managed from the admin dashboard.
 */
class OAuthProvider extends Model
{
    protected $table = 'oauth_providers';

    protected $fillable = [
        'provider',
        'is_enabled',
        'client_id',
        'client_secret',
        'sort_order',
    ];

    protected $hidden = [
        'client_secret',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'client_secret' => 'encrypted',
        'sort_order' => 'integer',
    ];

    /**
     * Determine whether enough credentials exist to start an OAuth redirect.
     */
    public function isConfigured(): bool
    {
        return filled($this->client_id) && filled($this->client_secret);
    }
}
