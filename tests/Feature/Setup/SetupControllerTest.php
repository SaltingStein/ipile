<?php

namespace Tests\Feature\Setup;

use App\Models\Setting;
use App\Models\User;
use App\Services\Setup\SuperAdminSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SetupControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_page_issues_a_key_to_the_log_on_first_visit(): void
    {
        Log::spy();

        $this->get(route('setup.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Setup'));

        $this->assertNotNull(Setting::valueOf(SuperAdminSetup::KEY_HASH));
        Log::shouldHaveReceived('notice')->once()->withArgs(fn (string $message) => str_contains($message, 'First-run setup key'));
    }

    public function test_revisiting_the_setup_page_keeps_the_issued_key(): void
    {
        $key = app(SuperAdminSetup::class)->issueKey();

        $this->get(route('setup.create'))->assertOk();

        $this->assertTrue(app(SuperAdminSetup::class)->keyMatches($key));
    }

    public function test_valid_key_creates_a_verified_super_admin_and_signs_them_in(): void
    {
        $key = app(SuperAdminSetup::class)->issueKey();

        $response = $this->post(route('setup.store'), $this->validInput(['setup_key' => strtolower($key)]));

        $response->assertRedirect(route('admin.index'));
        $user = User::query()->where('email', 'owner@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->hasRole('super_admin'));
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->is_guest);
        $this->assertNull(Setting::valueOf(SuperAdminSetup::KEY_HASH));
    }

    public function test_wrong_key_is_rejected(): void
    {
        app(SuperAdminSetup::class)->issueKey();

        $this->post(route('setup.store'), $this->validInput(['setup_key' => 'not-the-key']))
            ->assertSessionHasErrors('setup_key');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'owner@example.com']);
    }

    public function test_setup_is_closed_once_a_super_admin_exists(): void
    {
        $key = app(SuperAdminSetup::class)->issueKey();
        app(SuperAdminSetup::class)->promote(User::factory()->create());

        $this->get(route('setup.create'))->assertNotFound();
        $this->post(route('setup.store'), $this->validInput(['setup_key' => $key]))->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'owner@example.com']);
    }

    public function test_login_page_links_to_setup_only_while_it_is_required(): void
    {
        $this->get(route('login'))
            ->assertInertia(fn (Assert $page) => $page->where('setupUrl', route('setup.create')));

        app(SuperAdminSetup::class)->promote(User::factory()->create());

        $this->get(route('login'))
            ->assertInertia(fn (Assert $page) => $page->where('setupUrl', null));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validInput(array $overrides = []): array
    {
        return [
            'setup_key' => '',
            'name' => 'Site Owner',
            'email' => 'owner@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            ...$overrides,
        ];
    }
}
