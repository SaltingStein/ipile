<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

#[Signature('core:insight {name : Insight class name} {--table= : Table to count, e.g. projects (required)} {--permission= : Required global permission} {--scope=platform : platform (whole site) or team (the current team of the person asking; the table needs a team_id column)} {--team-ability=view : TeamPolicy ability for team insights} {--public : Explicitly safe for all allowed assistant users}')]
#[Description('Create an automatically discovered aggregate insight and its feature test')]
class MakeInsight extends Command
{
    protected $aliases = ['ipile:insight'];

    public function handle(Filesystem $files): int
    {
        $name = (string) $this->argument('name');
        $table = (string) $this->option('table');
        $permission = $this->option('permission');
        $scope = (string) $this->option('scope');
        $ability = (string) $this->option('team-ability');

        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) !== 1
            || preg_match('/^[a-z][a-z0-9_]*$/', $table) !== 1
            || preg_match('/^[a-z][A-Za-z0-9]*$/', $ability) !== 1
            || ! in_array($scope, ['platform', 'team'], true)
            || (! $this->option('public') && ! $permission)
            || ($permission !== null && preg_match('/^[a-z][a-z0-9_]*$/', $permission) !== 1)) {
            $this->components->error('Use a class name, --table=your_table, scope team/platform, and --permission=your_permission (or explicitly --public).');

            return self::FAILURE;
        }

        if ($scope === 'team' && Schema::hasTable($table) && ! Schema::hasColumn($table, 'team_id')) {
            $this->components->error("[{$table}] has no team_id column, so it cannot be limited to the asker's team. Use --scope=platform.");

            return self::FAILURE;
        }

        $path = app_path("Ai/Insights/{$name}.php");
        $testPath = base_path("tests/Feature/{$name}InsightTest.php");

        if ($files->exists($path) || $files->exists($testPath)) {
            $this->components->error('Nothing was changed: the insight or its feature test already exists.');

            return self::FAILURE;
        }

        $replacements = [
            '{{ name }}' => $name,
            '{{ key }}' => 'product.'.Str::snake($name),
            '{{ label }}' => Str::headline($name),
            '{{ table }}' => $table,
            '{{ scope }}' => $scope,
            '{{ ability }}' => $ability,
            '{{ permission }}' => $permission === null ? 'null' : "'{$permission}'",
            '{{ public }}' => $this->option('public') ? 'true' : 'false',
            '{{ permissions }}' => implode(', ', array_map(fn (string $granted) => "'{$granted}'", ['use_assistant_analytics', ...($permission ? [$permission] : [])])),
            '{{ teamImport }}' => $scope === 'team' ? "use App\\Models\\Team;\n" : '',
            '{{ teamSetup }}' => $scope === 'team'
                ? '        $team = Team::factory()->for($person, \'owner\')->create();'."\n"
                    .'        $person->forceFill([\'current_team_id\' => $team->id])->save();'."\n\n"
                : '',
        ];

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, strtr($files->get(__DIR__.'/stubs/insight.stub'), $replacements));
        $files->put($testPath, strtr($files->get(__DIR__.'/stubs/insight-test.stub'), $replacements));
        $this->components->info("Created {$path} and {$testPath}. It is discovered automatically and starts off in Admin → Site assistant.");

        return self::SUCCESS;
    }
}
