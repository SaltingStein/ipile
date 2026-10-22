<?php

namespace Database\Factories;

use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityEvent>
 */
class SecurityEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $userAgent = fake()->userAgent();
        $ipAddress = fake()->ipv4();

        return [
            'user_id' => User::factory(),
            'type' => 'auth.login',
            'category' => 'authentication',
            'ip_address' => $ipAddress,
            'device_hash' => hash('sha256', $userAgent),
            'network_hash' => hash('sha256', $ipAddress),
            'user_agent' => $userAgent,
            'risk_score' => 0,
            'posture_score' => 0,
            'signals' => [],
            'occurred_at' => now(),
        ];
    }

    /**
     * An event of the given catalog type.
     */
    public function ofType(string $type, string $category): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
            'category' => $category,
        ]);
    }

    /**
     * An event that scored as high risk.
     */
    public function risky(int $score = 85): static
    {
        return $this->state(fn (array $attributes) => [
            'risk_score' => $score,
            'recommended_action' => 'revoke_sessions',
        ]);
    }
}
