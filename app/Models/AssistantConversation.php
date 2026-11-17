<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One chat with the site assistant, owned by the person who started it. Only its owner can read or continue it
 * (administrators with `view_ai_activity` see single exchanges from the activity log).
 *
 * @property string $id
 * @property int $user_id
 * @property string $title
 */
class AssistantConversation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['user_id', 'title'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<AssistantMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class, 'conversation_id')->orderBy('id');
    }
}
