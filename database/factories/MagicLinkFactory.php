<?php

namespace Database\Factories;

use App\Models\MagicLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MagicLink>
 */
class MagicLinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'email' => fn (array $attributes) => User::query()->findOrFail($attributes['user_id'])->email,
            'token' => hash('sha256', Str::random(64)),
            'expires_at' => now()->addMinutes(15),
            'used_at' => null,
            'delivery_started_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => ['expires_at' => now()->subMinute()]);
    }

    public function used(): static
    {
        return $this->state(fn (array $attributes) => ['used_at' => now()]);
    }
}
