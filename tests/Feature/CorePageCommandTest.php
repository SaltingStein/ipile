<?php

namespace Tests\Feature;

use App\Services\Scaffolding\PageScaffolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CorePageCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = storage_path('framework/testing/core-page-'.uniqid());
        File::ensureDirectoryExists($this->project.'/routes');
        File::ensureDirectoryExists($this->project.'/resources/js');
        File::copy(base_path('routes/app.php'), $this->project.'/routes/app.php');
        File::copy(resource_path('js/appNavigation.ts'), $this->project.'/resources/js/appNavigation.ts');
        $this->app->instance(PageScaffolder::class, new PageScaffolder($this->project));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->project);

        parent::tearDown();
    }

    public function test_it_creates_a_signed_in_page_with_route_link_test_and_help_draft(): void
    {
        $this->artisan('ipile:page', ['name' => 'TeamReports', '--permission' => 'view_reports', '--nav' => true])
            ->expectsOutputToContain('The Team reports page is ready at /team-reports.')
            ->expectsOutputToContain('The view_reports permission does not exist yet.')
            ->assertSuccessful();

        $routes = $this->read('routes/app.php');
        $this->assertStringContainsString(
            "    Route::get('/team-reports', TeamReportsController::class)->middleware('permission:view_reports')->name('team-reports');\n    ".PageScaffolder::SIGNED_IN_MARKER,
            $routes,
        );
        $this->assertStringContainsString("use App\\Http\\Controllers\\DashboardController;\nuse App\\Http\\Controllers\\TeamReportsController;\nuse Illuminate\\Support\\Facades\\Route;", $routes);
        $this->assertStringContainsString("    { label: 'Team reports', href: '/team-reports', permission: 'view_reports' },\n    ".PageScaffolder::NAVIGATION_MARKER, $this->read('resources/js/appNavigation.ts'));

        $this->assertStringContainsString("Inertia::render('TeamReports'", $this->read('app/Http/Controllers/TeamReportsController.php'));
        $this->assertStringContainsString('<AppLayout title="Team reports">', $this->read('resources/js/pages/TeamReports.tsx'));
        $this->assertStringContainsString("Permission::findOrCreate('view_reports')", $this->read('tests/Feature/TeamReportsPageTest.php'));
        $help = $this->read('resources/help/team-reports.md');
        $this->assertStringContainsString("draft: true\npermission: view_reports\n---", $help);
        $this->assertStringContainsString('Choose **Team reports** in the menu.', $help);
    }

    public function test_it_creates_a_public_page_without_a_sidebar_link(): void
    {
        $this->artisan('ipile:page', ['name' => 'Pricing', '--public' => true, '--no-help' => true])->assertSuccessful();

        $this->assertStringContainsString("Route::get('/pricing', PricingController::class)->name('pricing');\n".PageScaffolder::PUBLIC_MARKER, $this->read('routes/app.php'));
        $this->assertStringContainsString('<PublicLayout title="Pricing">', $this->read('resources/js/pages/Pricing.tsx'));
        $this->assertStringContainsString('test_anyone_can_open_the_page', $this->read('tests/Feature/PricingPageTest.php'));
        $this->assertFileDoesNotExist($this->project.'/resources/help/pricing.md');
        $this->assertStringNotContainsString('Pricing', $this->read('resources/js/appNavigation.ts'));
    }

    public function test_it_never_overwrites_and_refuses_paths_or_names_already_in_use(): void
    {
        $this->artisan('ipile:page', ['name' => 'Reports'])->assertSuccessful();
        $routesAfterFirstRun = $this->read('routes/app.php');

        $this->artisan('ipile:page', ['name' => 'Reports'])
            ->expectsOutputToContain('app/Http/Controllers/ReportsController.php already exists')
            ->assertFailed();
        $this->assertSame($routesAfterFirstRun, $this->read('routes/app.php'));

        $this->artisan('ipile:page', ['name' => 'Dashboard'])
            ->expectsOutputToContain('A route named "dashboard" already exists')
            ->assertFailed();
        $this->artisan('ipile:page', ['name' => 'Overview', '--path' => '/help'])
            ->expectsOutputToContain('The path /help is already used')
            ->assertFailed();
    }

    public function test_invalid_input_is_explained(): void
    {
        $this->artisan('ipile:page', ['name' => '9lives'])->expectsOutputToContain('Use a name made of letters and numbers')->assertFailed();
        $this->artisan('ipile:page', ['name' => 'Reports', '--path' => '/Bad Path'])->expectsOutputToContain('Use a path of lowercase letters')->assertFailed();
        $this->artisan('ipile:page', ['name' => 'Pricing', '--public' => true, '--permission' => 'view_reports'])->expectsOutputToContain('A public page cannot require a permission')->assertFailed();
        $this->artisan('ipile:page', ['--no-interaction' => true])->expectsOutputToContain('Give the page a name')->assertFailed();
    }

    public function test_it_prints_the_line_to_add_when_a_marker_was_removed(): void
    {
        File::put($this->project.'/routes/app.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n");

        $this->artisan('ipile:page', ['name' => 'Reports', '--no-help' => true])
            ->expectsOutputToContain("Add to routes/app.php: Route::get('/reports', ReportsController::class)->name('reports');")
            ->assertSuccessful();
    }

    public function test_the_interactive_wizard_asks_for_each_choice(): void
    {
        $this->artisan('ipile:page')
            ->expectsQuestion('Page name', 'Reports')
            ->expectsQuestion('Who can open it?', 'signed-in')
            ->expectsQuestion('Limit it to users with a permission?', '')
            ->expectsQuestion('URL path', '/reports')
            ->expectsConfirmation('Add it to the workspace sidebar?', 'yes')
            ->expectsConfirmation('Create a draft help article for your users?', 'no')
            ->assertSuccessful();

        $this->assertStringContainsString("{ label: 'Reports', href: '/reports' },", $this->read('resources/js/appNavigation.ts'));
        $this->assertFileDoesNotExist($this->project.'/resources/help/reports.md');
    }

    private function read(string $relative): string
    {
        return File::get($this->project.'/'.$relative);
    }
}
