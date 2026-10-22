<?php

namespace App\Console\Commands;

use App\Services\Scaffolding\PageBlueprint;
use App\Services\Scaffolding\PageScaffolder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Adds a working page in one step. Run it without a name to be asked for each choice.
 */
#[Signature('ipile:page
    {name? : Page name, e.g. Reports or TeamReports}
    {--path= : URL path (default: the name in kebab-case, e.g. /team-reports)}
    {--permission= : Only users with this permission can open the page}
    {--nav : Add a link to the workspace sidebar}
    {--public : Anyone can open the page, signed in or not}
    {--no-help : Do not create a draft help article}')]
#[Description('Create a page with its route, controller, test, optional sidebar link and a draft help article')]
class MakeCorePage extends Command
{
    public function handle(PageScaffolder $scaffolder): int
    {
        $details = [
            'name' => $this->argument('name'),
            'path' => $this->option('path'),
            'permission' => $this->option('permission') ?: null,
            'public' => (bool) $this->option('public'),
            'nav' => (bool) $this->option('nav'),
            'help' => ! $this->option('no-help'),
        ];

        if ($details['name'] === null) {
            if (! $this->input->isInteractive()) {
                $this->components->error('Give the page a name, e.g. php artisan ipile:page Reports');

                return self::FAILURE;
            }

            $details = $this->askForDetails();
        }

        try {
            $page = new PageBlueprint($details['name'], $details['path'], $details['permission'], $details['public'], $details['nav'], $details['help']);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if (($problems = $scaffolder->problems($page)) !== []) {
            $this->components->error('Nothing was changed:');
            $this->components->bulletList($problems);

            return self::FAILURE;
        }

        $result = $scaffolder->create($page);

        $this->components->info("The {$page->title} page is ready at {$page->path}.");
        $this->components->twoColumnDetail('<fg=green>Created</>', implode(', ', $result['created']));
        if ($result['updated'] !== []) {
            $this->components->twoColumnDetail('<fg=green>Updated</>', implode(', ', $result['updated']));
        }

        foreach ($result['manual'] as $step) {
            $this->components->warn($step);
        }

        if ($page->permission !== null && $this->permissionIsMissing($page->permission)) {
            $this->components->warn("The {$page->permission} permission does not exist yet. Add it to RolesAndPermissionsSeeder::PERMISSIONS, run `php artisan db:seed --class=RolesAndPermissionsSeeder`, then grant it to a role in Admin → Roles & permissions.");
        }

        $this->components->bulletList(array_filter([
            "Build the page in resources/js/pages/{$page->className}.tsx (data comes from {$page->className}Controller).",
            $page->help ? "Write the help article in resources/help/{$page->slug}.md for end users, then remove `draft: true`." : null,
            "Run the test: php artisan test --filter={$page->className}PageTest",
            'Keep `npm run dev` running (or run `npm run build`) to see the page in the browser.',
        ]));

        return self::SUCCESS;
    }

    /**
     * @return array{name: string, path: ?string, permission: ?string, public: bool, nav: bool, help: bool}
     */
    private function askForDetails(): array
    {
        $name = text('Page name', placeholder: 'e.g. Reports', required: true, validate: function (string $value): ?string {
            try {
                new PageBlueprint($value);

                return null;
            } catch (InvalidArgumentException $exception) {
                return $exception->getMessage();
            }
        });

        $public = select('Who can open it?', [
            'signed-in' => 'Signed-in users',
            'public' => 'Anyone (a public page)',
        ]) === 'public';

        $permission = $public ? '' : text('Limit it to users with a permission?', placeholder: 'e.g. view_reports (leave blank for every signed-in user)');
        $path = text('URL path', default: '/'.Str::kebab(Str::studly($name)), required: true);
        $nav = ! $public && confirm('Add it to the workspace sidebar?', default: true);
        $help = confirm('Create a draft help article for your users?', default: true);

        return ['name' => $name, 'path' => $path, 'permission' => $permission !== '' ? $permission : null, 'public' => $public, 'nav' => $nav, 'help' => $help];
    }

    private function permissionIsMissing(string $permission): bool
    {
        return Schema::hasTable('permissions') && ! Permission::query()->where('name', $permission)->exists();
    }
}
