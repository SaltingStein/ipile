<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A turn in an {@see AssistantConversation}: the person's message, or the assistant's reply as shown (after
 * AssistantGuard::scrub()), with the intent and mode that answered it.
 *
 * @property int $id
 * @property string $conversation_id
 * @property string $role
 * @property string $content
 * @property string|null $intent
 * @property string|null $mode
 */
class AssistantMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const UPDATED_AT = null;

    protected $fillable = ['conversation_id', 'role', 'content', 'intent', 'mode'];

    /**
     * @return BelongsTo<AssistantConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }
}
