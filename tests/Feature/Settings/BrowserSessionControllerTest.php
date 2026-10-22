<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BrowserSessionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    public function test_correct_password_signs_out_other_sessions_of_this_user_only(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->storeSession('users-other-browser', $user);
        $this->storeSession('someone-elses-browser', $otherUser);
        $originalPasswordHash = $user->password;

        $response = $this->confirmedAs($user)->delete(route('browser-sessions.destroy'), ['password' => 'password']);

        $response->assertSessionHas('status', 'Other browser sessions have been signed out.');
        $this->assertDatabaseMissing('sessions', ['id' => 'users-other-browser']);
        $this->assertDatabaseHas('sessions', ['id' => 'someone-elses-browser']);
        $this->assertNotSame($originalPasswordHash, $user->fresh()->password);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_wrong_password_signs_nothing_out(): void
    {
        $user = User::factory()->create();
        $this->storeSession('users-other-browser', $user);

        $response = $this->confirmedAs($user)->delete(route('browser-sessions.destroy'), ['password' => 'wrong-password']);

        $response->assertSessionHasErrors(['password' => 'The password is incorrect.']);
        $this->assertDatabaseHas('sessions', ['id' => 'users-other-browser']);
    }

    public function test_account_without_a_password_rotates_its_remember_token(): void
    {
        $user = User::factory()->withoutPassword()->create(['remember_token' => 'old-remember-token']);
        $this->storeSession('users-other-browser', $user);

        $this->confirmedAs($user)->delete(route('browser-sessions.destroy'));

        $this->assertNotSame('old-remember-token', $user->fresh()->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'users-other-browser']);
    }

    private function storeSession(string $id, User $user): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '10.0.0.1',
            'user_agent' => 'Test Browser',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ]);
    }

    private function confirmedAs(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }
}
