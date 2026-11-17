<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\TeamSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamSetting>
 */
class TeamSettingFactory extends Factory
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
            'key' => 'example.'.fake()->unique()->word(),
            'value' => fake()->word(),
        ];
    }
}
