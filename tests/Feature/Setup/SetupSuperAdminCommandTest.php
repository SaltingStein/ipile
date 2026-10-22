<?php

namespace Tests\Feature\Setup;

use App\Models\Setting;
use App\Models\User;
use App\Services\Setup\SuperAdminSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_super_admin_in_the_terminal(): void
    {
        $this->artisan('ipile:setup')
            ->expectsChoice('How do you want to create the super admin?', 'terminal', ['terminal' => 'Here in the terminal', 'web' => 'In the browser (prints a one-time setup key)'])
            ->expectsQuestion('Email address', 'Owner@Example.com')
            ->expectsQuestion('Name', 'Site Owner')
            ->expectsQuestion('Password', 'secret123')
            ->expectsQuestion('Confirm password', 'secret123')
            ->assertSuccessful();

        $user = User::query()->where('email', 'owner@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('super_admin'));
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_promotes_an_existing_account_after_confirmation(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);

        $this->artisan('ipile:setup')
            ->expectsChoice('How do you want to create the super admin?', 'terminal', ['terminal' => 'Here in the terminal', 'web' => 'In the browser (prints a one-time setup key)'])
            ->expectsQuestion('Email address', 'owner@example.com')
            ->expectsConfirmation('owner@example.com already has an account. Make it the super admin?', 'yes')
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->hasRole('super_admin'));
    }

    public function test_without_a_terminal_it_prints_a_web_setup_key(): void
    {
        $this->artisan('ipile:setup', ['--no-interaction' => true])
            ->expectsOutputToContain(route('setup.create'))
            ->assertSuccessful();

        $this->assertNotNull(Setting::valueOf(SuperAdminSetup::KEY_HASH));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_does_nothing_once_a_super_admin_exists(): void
    {
        app(SuperAdminSetup::class)->promote(User::factory()->create());

        $this->artisan('ipile:setup')
            ->expectsOutputToContain('Setup is already complete')
            ->assertSuccessful();

        $this->assertNull(Setting::valueOf(SuperAdminSetup::KEY_HASH));
    }

    public function test_setup_key_command_refuses_once_setup_is_complete(): void
    {
        app(SuperAdminSetup::class)->promote(User::factory()->create());

        $this->artisan('ipile:setup-key')->assertFailed();
    }
}
