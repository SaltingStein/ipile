<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\AuthenticationSettings;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegisterControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_registration_returns_201_with_a_token(): void
    {
        Notification::fake();

        $response = $this->postJson(route('api.v1.auth.register'), $this->payload());

        $response->assertCreated();
        $response->assertJsonPath('user.email', 'ada@example.com');
        $response->assertJsonPath('user.is_guest', false);
        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id, 'name' => 'Ada Pixel']);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_taken_email_returns_422(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson(route('api.v1.auth.register'), $this->payload())
            ->assertJsonValidationErrors(['email' => 'The email has already been taken.']);
    }

    public function test_closed_registration_returns_403(): void
    {
        Setting::store(AuthenticationSettings::REGISTRATION_ENABLED, '0');

        $this->postJson(route('api.v1.auth.register'), $this->payload())->assertForbidden();

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * @return array<string, string>
     */
    private function payload(): array
    {
        return [
            'name' => 'Ada Obi',
            'email' => 'ada@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'device_name' => 'Ada Pixel',
        ];
    }
}
