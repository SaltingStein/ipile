<?php

namespace Database\Factories;

use App\Models\Form;
use App\Models\FormSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Answers matching {@see FormFactory}'s default fields.
 *
 * @extends Factory<FormSubmission>
 */
class FormSubmissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'user_id' => null,
            'data' => [
                'full_name' => fake()->name(),
                'email' => fake()->safeEmail(),
                'ticket' => 'Standard',
            ],
        ];
    }
}
