<?php

namespace Database\Factories;

use App\Models\Form;
use App\Models\Team;
use App\Services\Forms\FormFieldType;
use App\Services\Forms\FormStatus;
use App\Services\Forms\FormTemplates;
use App\Services\Forms\RegistrationForm;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A published team form with a required name, an email and a single-choice question.
 *
 * @extends Factory<Form>
 */
class FormFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->words(3, true);

        return [
            'team_id' => Team::factory(),
            'title' => Str::title($title),
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'description' => null,
            'status' => FormStatus::Published,
            'fields' => [
                FormTemplates::field(FormFieldType::ShortText, 'full_name', 'Full name', required: true),
                FormTemplates::field(FormFieldType::Email, 'email', 'Email'),
                FormTemplates::field(FormFieldType::SingleChoice, 'ticket', 'Ticket', options: ['Standard', 'Student']),
            ],
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => FormStatus::Draft]);
    }

    public function closed(): static
    {
        return $this->state(['status' => FormStatus::Closed]);
    }

    /**
     * A site form (no team), managed with `manage_forms`.
     */
    public function site(): static
    {
        return $this->state(['team_id' => null]);
    }

    /**
     * The sign-up questions (site form, no public address).
     */
    public function registration(): static
    {
        return $this->site()->state(['purpose' => RegistrationForm::PURPOSE, 'slug' => null, 'title' => 'Sign-up questions']);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    public function withFields(array $fields): static
    {
        return $this->state(['fields' => $fields]);
    }
}
