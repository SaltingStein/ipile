<?php

namespace Tests\Feature\Setup;

use App\Models\Setting;
use App\Models\User;
use App\Services\Setup\SuperAdminSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FirstRunSetupTest extends TestCase
{
    use RefreshDatabase;

    protected bool $redirectsToSetup = true;

    public function test_pages_open_the_setup_screen_until_a_super_admin_exists(): void
    {
        $this->get(route('home'))->assertRedirect(route('setup.create'));
        $this->get(route('login'))->assertRedirect(route('setup.create'));
    }

    public function test_nobody_can_register_before_the_site_is_claimed(): void
    {
        $this->post('/register', [
            'name' => 'Early Bird',
            'email' => 'early@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertRedirect(route('setup.create'));

        $this->assertDatabaseMissing('users', ['email' => 'early@example.com']);
    }

    public function test_json_requests_are_told_to_finish_setup(): void
    {
        $this->getJson(route('home'))
            ->assertStatus(503)
            ->assertJson(['setup_url' => route('setup.create')]);
    }

    public function test_the_setup_screen_and_health_check_stay_open(): void
    {
        $this->get(route('setup.create'))->assertOk();
        $this->get('/up')->assertOk();
    }

    public function test_pages_open_normally_once_a_super_admin_exists(): void
    {
        app(SuperAdminSetup::class)->promote(User::factory()->create());

        $this->get(route('login'))->assertOk();
        $this->get(route('setup.create'))->assertNotFound();
        $this->assertNotNull(Setting::valueOf(SuperAdminSetup::COMPLETED_AT));
    }

    public function test_server_checks_fail_over_plain_http_on_another_address(): void
    {
        config(['app.url' => 'https://example.com', 'app.debug' => true]);

        $this->get('http://other.test/setup')->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Setup')
            ->where('checks', fn ($checks) => collect($checks)->whereIn('key', ['https', 'app_url', 'debug'])->every(fn (array $check) => $check['ok'] === false && $check['required'])));
    }

    public function test_server_checks_pass_over_https_on_the_configured_address(): void
    {
        config(['app.url' => 'https://example.com', 'app.debug' => false]);

        $this->get('https://example.com/setup')->assertInertia(fn (Assert $page) => $page
            ->where('checks', fn ($checks) => collect($checks)->whereIn('key', ['https', 'app_url', 'debug'])->every(fn (array $check) => $check['ok'] === true)));
    }

    public function test_a_site_without_database_tables_shows_the_install_steps(): void
    {
        Schema::drop('users');

        $this->get(route('home'))
            ->assertStatus(503)
            ->assertSee('Finish installing the site')
            ->assertSee('php artisan migrate --force');
    }

    public function test_a_database_error_on_an_installed_site_is_not_mistaken_for_a_fresh_install(): void
    {
        config(['app.debug' => false]);
        app(SuperAdminSetup::class)->promote(User::factory()->create());
        Route::middleware('web')->get('/broken-query', fn () => DB::select('select * from table_that_does_not_exist'));

        $this->get('/broken-query')
            ->assertStatus(500)
            ->assertDontSee('Finish installing the site');
    }
}
