<?php

namespace Tests\Feature;

use App\Services\Security\SecurityEventDefinition;
use App\Services\Security\Signals\SecuritySignal;
use App\Services\Security\Signals\SignalContext;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Covers `php artisan make:security-signal`.
 */
class MakeSecuritySignalCommandTest extends TestCase
{
    private const NAME = 'GeneratorCheckRefund';

    private string $classPath;

    private string $testPath;

    /** @var list<string> */
    private array $createdDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->classPath = app_path('Security/Signals/'.self::NAME.'Signal.php');
        $this->testPath = base_path('tests/Feature/Security/Signals/'.self::NAME.'SignalTest.php');

        foreach ([app_path('Security'), app_path('Security/Signals'), base_path('tests/Feature/Security'), base_path('tests/Feature/Security/Signals')] as $directory) {
            if (! File::isDirectory($directory)) {
                $this->createdDirectories[] = $directory;
            }
        }
    }

    protected function tearDown(): void
    {
        File::delete([$this->classPath, $this->testPath]);

        foreach (array_reverse($this->createdDirectories) as $directory) {
            if (File::isDirectory($directory) && File::isEmptyDirectory($directory)) {
                File::deleteDirectory($directory);
            }
        }

        parent::tearDown();
    }

    public function test_it_creates_a_working_signal_and_its_test(): void
    {
        $this->artisan('make:security-signal', ['name' => self::NAME])->assertSuccessful();

        $this->assertFileExists($this->classPath);
        $this->assertFileExists($this->testPath);
        $this->assertSame(0, $this->lint($this->testPath));

        require_once $this->classPath;
        $class = 'App\\Security\\Signals\\'.self::NAME.'Signal';
        $signal = new $class;
        $context = new SignalContext(new SecurityEventDefinition('test.unrelated', 'testing', 'Test', 'Used by tests.'), null, null, null, null, now(), null, []);

        $this->assertInstanceOf(SecuritySignal::class, $signal);
        $this->assertSame('generator_check_refund', $signal->key());
        $this->assertSame('Generator check refund', $signal->label());
        $this->assertSame(0.0, $signal->evaluate($context)->score);
    }

    public function test_it_never_overwrites_an_existing_signal(): void
    {
        $this->artisan('make:security-signal', ['name' => self::NAME.'Signal', '--no-test' => true])->assertSuccessful();
        File::put($this->classPath, '<?php // edited');

        $this->artisan('make:security-signal', ['name' => self::NAME])->assertFailed();

        $this->assertSame('<?php // edited', File::get($this->classPath));
        $this->assertFileDoesNotExist($this->testPath);
    }

    private function lint(string $path): int
    {
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path), $output, $exitCode);

        return $exitCode;
    }
}
