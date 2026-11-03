<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\AssistantController;
use App\Http\Controllers\Controller;
use App\Models\AiInteraction;
use App\Services\Assistant\AssistantIntent;
use App\Services\Assistant\AssistantSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Admin → Platform → Assistant activity: every site assistant request (CORE-913) with what the person was allowed at
 * the time, filterable by person, role, mode, outcome and date; one row opens the exchange and the tools used.
 * Routes require `view_ai_activity`.
 */
class AssistantActivityController extends Controller
{
    public const PER_PAGE = 25;

    public function index(Request $request, AssistantSettings $settings): Response
    {
        $filters = $request->validate([
            'person' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'max:100'],
            'mode' => ['nullable', Rule::in(array_map(fn (AssistantIntent $mode) => $mode->value, AssistantIntent::cases()))],
            'outcome' => ['nullable', Rule::in(AiInteraction::OUTCOMES)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $interactions = AiInteraction::query()
            ->with('user:id,name,nickname,email')
            ->when($filters['person'] ?? null, fn (Builder $query, string $person) => $query->whereHas('user', fn (Builder $user) => $user
                ->where('email', 'like', "%{$person}%")->orWhere('name', 'like', "%{$person}%")->orWhere('nickname', 'like', "%{$person}%")))
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query->whereJsonContains('roles', $role))
            ->when($filters['mode'] ?? null, fn (Builder $query, string $mode) => $query->where('mode', $mode))
            ->when($filters['outcome'] ?? null, fn (Builder $query, string $outcome) => $query->where('outcome', $outcome))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('created_at', '<', now()->parse($to)->addDay()))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (AiInteraction $interaction) => $this->summary($interaction));

        return Inertia::render('Admin/AssistantActivity', [
            'interactions' => $interactions,
            'filters' => array_merge(['person' => '', 'role' => '', 'mode' => '', 'outcome' => '', 'from' => '', 'to' => ''], array_filter($filters)),
            'roles' => Role::query()->orderBy('name')->pluck('name'),
            'modes' => array_map(fn (AssistantIntent $mode) => ['value' => $mode->value, 'label' => $mode->label()], AssistantIntent::cases()),
            'outcomes' => array_map(fn (string $outcome) => ['value' => $outcome, 'label' => self::outcomeLabel($outcome)], AiInteraction::OUTCOMES),
            'retentionDays' => $settings->activityRetentionDays(),
        ]);
    }

    public function show(AiInteraction $interaction): Response
    {
        $interaction->load(['user:id,name,nickname,email', 'userMessage', 'assistantMessage']);

        return Inertia::render('Admin/AssistantInteraction', [
            'interaction' => [
                ...$this->summary($interaction),
                'conversation_id' => $interaction->conversation_id,
                'team_id' => $interaction->team_id,
                'team_role' => $interaction->team_role,
                'capabilities' => $interaction->capabilities,
                'tools' => $interaction->tools,
                'input_tokens' => $interaction->input_tokens,
                'output_tokens' => $interaction->output_tokens,
                'question' => $interaction->userMessage?->content,
                'answer_html' => $interaction->assistantMessage === null ? null : AssistantController::toHtml($interaction->assistantMessage->content),
            ],
        ]);
    }

    public static function outcomeLabel(string $outcome): string
    {
        return match ($outcome) {
            AiInteraction::ANSWERED => 'Answered',
            AiInteraction::REFUSED => 'Refused (protected data)',
            AiInteraction::DENIED_BY_SCOPE => 'Beyond their access',
            AiInteraction::RATE_LIMITED => 'Over the daily limit',
            AiInteraction::PROVIDER_ERROR => 'AI service error',
            default => $outcome,
        };
    }

    /**
     * @return array{id: int, created_at: string|null, person: array{id: int, name: string, email: string|null}|null, roles: list<string>, channel: string, mode: string|null, outcome: string, outcome_label: string, provider: string|null, model: string|null, tool_count: int, duration_ms: int|null}
     */
    private function summary(AiInteraction $interaction): array
    {
        return [
            'id' => $interaction->id,
            'created_at' => $interaction->created_at?->toIso8601String(),
            'person' => $interaction->user === null ? null : ['id' => $interaction->user->id, 'name' => $interaction->user->display_name, 'email' => $interaction->user->email],
            'roles' => $interaction->roles,
            'channel' => $interaction->channel,
            'mode' => AssistantIntent::tryFrom((string) $interaction->mode)?->label(),
            'outcome' => $interaction->outcome,
            'outcome_label' => self::outcomeLabel($interaction->outcome),
            'provider' => $interaction->provider,
            'model' => $interaction->model,
            'tool_count' => count($interaction->tools),
            'duration_ms' => $interaction->duration_ms,
        ];
    }
}
