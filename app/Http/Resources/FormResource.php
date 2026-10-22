<?php

namespace App\Http\Resources;

use App\Models\Form;
use App\Services\Forms\FormMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A form for the builder and form lists. Fields are included only when `withFields()` is used, so lists stay small.
 *
 * @mixin Form
 */
class FormResource extends JsonResource
{
    private bool $includeFields = false;

    /**
     * Include the field definitions (the builder needs them; lists do not).
     */
    public function withFields(): static
    {
        $this->includeFields = true;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'status' => $this->status->value,
            'is_registration' => $this->isRegistrationForm(),
            'team' => $this->team_id === null ? null : ['id' => $this->team_id, 'name' => $this->team?->name],
            'public_url' => $this->publicUrl(),
            'submit_label' => $this->submit_label,
            'success_message' => $this->success_message,
            'max_submissions' => $this->max_submissions,
            'closes_at' => $this->closes_at?->toIso8601String(),
            'sends_confirmation' => $this->sends_confirmation,
            'layout' => $this->layout->value,
            'media' => app(FormMedia::class)->details($this->resource),
            'submissions_count' => $this->whenCounted('submissions'),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'fields' => $this->when($this->includeFields, fn () => $this->fields),
        ];
    }
}
