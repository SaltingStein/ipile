<?php

namespace App\Models;

use App\Services\Forms\FormFieldType;
use App\Services\Forms\FormLayout;
use App\Services\Forms\FormStatus;
use App\Services\Forms\RegistrationForm;
use Database\Factories\FormFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A form built in the drag-and-drop builder. Team forms belong to a team and are managed by its owner and
 * admins; site forms (no team) are managed by staff with `manage_forms`, and include the sign-up form.
 *
 * @phpstan-type FormField array{id: string, key: string, type: string, label: string, hint: string|null, placeholder: string|null, required: bool, options: list<string>, min: int|null, max: int|null}
 */
class Form extends Model
{
    /** @use HasFactory<FormFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'status',
        'fields',
        'submit_label',
        'success_message',
        'max_submissions',
        'closes_at',
        'sends_confirmation',
        'layout',
    ];

    protected $casts = [
        'fields' => 'array',
        'status' => FormStatus::class,
        'max_submissions' => 'integer',
        'closes_at' => 'datetime',
        'sends_confirmation' => 'boolean',
        'layout' => FormLayout::class,
    ];

    protected $attributes = [
        'fields' => '[]',
        'status' => 'draft',
        'layout' => 'simple',
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<FormSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    /**
     * Forms that belong to the site rather than a team.
     *
     * @param  Builder<Form>  $query
     */
    public function scopeForSite(Builder $query): void
    {
        $query->whereNull('team_id');
    }

    public function isSiteForm(): bool
    {
        return $this->team_id === null;
    }

    /**
     * The extra sign-up questions. It cannot be deleted or opened at its own address.
     */
    public function isRegistrationForm(): bool
    {
        return $this->purpose === RegistrationForm::PURPOSE;
    }

    /**
     * Fields that collect an answer, in form order (headings left out).
     *
     * @return list<FormField>
     */
    public function inputFields(): array
    {
        return array_values(array_filter(
            $this->fields ?? [],
            fn (array $field) => FormFieldType::tryFrom($field['type'])?->collectsInput() ?? false,
        ));
    }

    /**
     * Why the form is not taking answers right now, or null when it is.
     */
    public function closedReason(): ?string
    {
        if ($this->status === FormStatus::Draft) {
            return __('This form is not published yet.');
        }

        if ($this->status === FormStatus::Closed || ($this->closes_at !== null && $this->closes_at->isPast())) {
            return __('This form is closed and no longer accepts responses.');
        }

        if ($this->isFull()) {
            return __('This form has reached its response limit.');
        }

        return null;
    }

    /**
     * Whether the response limit has been reached. Uses a loaded `submissions_count` when there is one.
     */
    public function isFull(): bool
    {
        if ($this->max_submissions === null) {
            return false;
        }

        return ($this->submissions_count ?? $this->submissions()->count()) >= $this->max_submissions;
    }

    /**
     * The public address, or null for forms that are not opened on their own.
     */
    public function publicUrl(): ?string
    {
        return $this->slug === null ? null : route('forms.public.show', $this->slug);
    }
}
