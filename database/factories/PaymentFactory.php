<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
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
            'amount' => fake()->randomFloat(2, 100, 50000),
            'currency' => 'NGN',
            'reference' => 'pay-'.Str::lower((string) Str::ulid()),
            'provider' => 'squad',
            'status' => 'pending',
            'payment_method' => 'card',
        ];
    }
}
