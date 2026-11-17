<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One request to the site assistant (CORE-913), recorded server-side whatever the outcome, with a snapshot of what the
 * person was allowed at that moment (global roles, current team and team role, modes, tools and insights offered), so
 * later role changes never rewrite history. Tool calls keep the tool, its argument names and short scrubbed values,
 * and the size of the result, never the data. Append-only: rows are never changed, only pruned after the retention
 * period (Admin → Site assistant).
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $conversation_id
 * @property string $channel
 * @property string $outcome
 * @property list<string> $roles
 * @property array<string, mixed> $capabilities
 * @property list<array{tool: string, arguments: array<string, string>, result_bytes: int}> $tools
 */
class AiInteraction extends Model
{
    public const ANSWERED = 'answered';

    public const REFUSED = 'refused';

    public const DENIED_BY_SCOPE = 'denied_by_scope';

    public const RATE_LIMITED = 'rate_limited';

    public const PROVIDER_ERROR = 'provider_error';

    public const OUTCOMES = [self::ANSWERED, self::REFUSED, self::DENIED_BY_SCOPE, self::RATE_LIMITED, self::PROVIDER_ERROR];

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'capabilities' => 'array',
            'tools' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Assistant interactions are append-only.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<AssistantMessage, $this>
     */
    public function userMessage(): BelongsTo
    {
        return $this->belongsTo(AssistantMessage::class, 'user_message_id');
    }

    /**
     * @return BelongsTo<AssistantMessage, $this>
     */
    public function assistantMessage(): BelongsTo
    {
        return $this->belongsTo(AssistantMessage::class, 'assistant_message_id');
    }
}
