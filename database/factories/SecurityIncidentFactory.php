<?php

namespace Database\Factories;

use App\Models\SecurityIncident;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityIncident>
 */
class SecurityIncidentFactory extends Factory
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
            'ip_address' => fake()->ipv4(),
            'status' => SecurityIncident::STATUS_OPEN,
            'risk_score' => 80,
            'risk_level' => 'high',
            'recommended_action' => 'step_up',
            'event_count' => 1,
            'first_event_at' => now(),
            'last_event_at' => now(),
        ];
    }

    /**
     * An incident an administrator has closed.
     */
    public function resolved(string $resolution = 'benign'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SecurityIncident::STATUS_RESOLVED,
            'resolution' => $resolution,
            'resolved_at' => now(),
        ]);
    }
}
