<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A string in one language, written by the AI model (`is_manual` false) or by an admin.
 */
class Translation extends Model
{
    protected $fillable = [
        'locale',
        'text',
        'is_manual',
    ];

    protected $casts = [
        'is_manual' => 'boolean',
    ];

    /**
     * @return BelongsTo<TranslationString, $this>
     */
    public function string(): BelongsTo
    {
        return $this->belongsTo(TranslationString::class, 'translation_string_id');
    }
}
