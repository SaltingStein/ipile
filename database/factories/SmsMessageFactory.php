<?php

namespace Database\Factories;

use App\Models\SmsMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmsMessage>
 */
class SmsMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'smpp',
            'to' => '2348031234567',
            'sender' => 'Ipile',
            'body' => fake()->sentence(),
            'status' => SmsMessage::STATUS_QUEUED,
        ];
    }

    /**
     * Indicate that the SMSC accepted the message.
     */
    public function sent(string $providerMessageId = 'msg-1'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SmsMessage::STATUS_SENT,
            'provider_message_id' => $providerMessageId,
            'sent_at' => now(),
        ]);
    }
}
