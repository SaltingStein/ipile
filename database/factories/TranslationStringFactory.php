<?php

namespace Database\Factories;

use App\Models\TranslationString;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A confirmed string, seen by two visitors.
 *
 * @extends Factory<TranslationString>
 */
class TranslationStringFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $text = fake()->unique()->sentence(3);

        return [
            'hash' => TranslationString::hashOf($text),
            'text' => $text,
            'reporter_hash' => hash('sha256', fake()->ipv4()),
            'confirmed_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    public function unconfirmed(): static
    {
        return $this->state(['confirmed_at' => null]);
    }

    public function text(string $text): static
    {
        return $this->state(['text' => $text, 'hash' => TranslationString::hashOf($text)]);
    }
}
