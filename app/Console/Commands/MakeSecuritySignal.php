<?php

namespace App\Console\Commands;

use App\Services\Security\Security;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;

/**
 * Creates an Aegis signal class in `app/Security/Signals` and a feature test for it. Register the signal with
 * {@see Security::signal()} in a service provider's `boot()`.
 */
#[Signature('make:security-signal
    {name : Signal name, e.g. UnusualRefund (Signal is added when missing)}
    {--force : Overwrite the signal class if it exists}
    {--no-test : Do not create a test}')]
#[Description('Create an Aegis security signal class and its test')]
class MakeSecuritySignal extends GeneratorCommand
{
    protected $type = 'Security signal';

    public function handle(): int
    {
        if (parent::handle() === false) {
            return self::FAILURE;
        }

        if (! $this->option('no-test')) {
            $this->writeTest();
        }

        $this->components->info('Register it in a service provider\'s boot(): Security::signal(\\'.$this->qualifyClass($this->getNameInput()).'::class);');

        return self::SUCCESS;
    }

    protected function getNameInput(): string
    {
        $name = Str::studly(trim((string) $this->argument('name')));

        return Str::endsWith($name, 'Signal') ? $name : $name.'Signal';
    }

    protected function getStub(): string
    {
        return __DIR__.'/stubs/security-signal.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Security\\Signals';
    }

    protected function buildClass($name): string
    {
        return str_replace(['{{ key }}', '{{ label }}'], [$this->signalKey(), $this->signalLabel()], parent::buildClass($name));
    }

    private function writeTest(): void
    {
        $class = class_basename($this->qualifyClass($this->getNameInput()));
        $path = $this->laravel->basePath('tests/Feature/Security/Signals/'.$class.'Test.php');

        if ($this->files->exists($path) && ! $this->option('force')) {
            $this->components->warn("The test [{$path}] already exists, so it was left as it is.");

            return;
        }

        $this->makeDirectory($path);
        $this->files->put($path, str_replace(
            ['{{ class }}', '{{ namespacedClass }}', '{{ key }}'],
            [$class, $this->qualifyClass($this->getNameInput()), $this->signalKey()],
            $this->files->get(__DIR__.'/stubs/security-signal-test.stub'),
        ));

        $this->components->info("Test [{$path}] created successfully.");
    }

    private function signalKey(): string
    {
        return Str::snake(Str::beforeLast($this->getNameInput(), 'Signal'));
    }

    private function signalLabel(): string
    {
        return Str::ucfirst(Str::lower(Str::headline(Str::beforeLast($this->getNameInput(), 'Signal'))));
    }
}
