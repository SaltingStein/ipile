<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Services\Teams\TeamRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamInvitation>
 */
class TeamInvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => TeamRole::Member->value,
        ];
    }
}
