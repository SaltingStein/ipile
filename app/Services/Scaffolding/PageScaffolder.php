<?php

namespace App\Services\Scaffolding;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * Creates a working page for `ipile:page`: Inertia page, invokable controller, route in routes/app.php,
 * feature test, optional sidebar link and a draft help article. Never overwrites anything.
 *
 * Templates live in ./stubs; edit them to change what new pages look like.
 */
class PageScaffolder
{
    public const SIGNED_IN_MARKER = '// ipile:page adds signed-in pages above this line.';

    public const PUBLIC_MARKER = '// ipile:page adds public pages above this line.';

    public const NAVIGATION_MARKER = '// ipile:page adds links above this line.';

    private readonly string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = rtrim($basePath ?? base_path(), '/\\');
    }

    /**
     * Reasons the page cannot be created as described. Empty when it is safe to go ahead.
     *
     * @return list<string>
     */
    public function problems(PageBlueprint $page): array
    {
        $problems = [];

        foreach ($this->files($page) as $file) {
            if (File::exists($this->absolute($file))) {
                $problems[] = "{$file} already exists.";
            }
        }

        if (Route::has($page->routeName)) {
            $problems[] = "A route named \"{$page->routeName}\" already exists.";
        }

        foreach (Route::getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true) && '/'.ltrim($route->uri(), '/') === $page->path) {
                $problems[] = "The path {$page->path} is already used.";
                break;
            }
        }

        return $problems;
    }

    /**
     * Create the page. Returns the files written and any steps that could not be done automatically.
     *
     * @return array{created: list<string>, updated: list<string>, manual: list<string>}
     */
    public function create(PageBlueprint $page): array
    {
        if (($problems = $this->problems($page)) !== []) {
            throw new RuntimeException(implode(' ', $problems));
        }

        $result = ['created' => [], 'updated' => [], 'manual' => []];

        foreach ($this->files($page) as $template => $file) {
            File::ensureDirectoryExists(dirname($this->absolute($file)));
            File::put($this->absolute($file), $this->render($template, $page));
            $result['created'][] = $file;
        }

        $this->addRoute($page, $result);

        if ($page->nav) {
            $this->addNavigationLink($page, $result);
        }

        return $result;
    }

    /**
     * Template name => file to create, relative to the project root.
     *
     * @return array<string, string>
     */
    public function files(PageBlueprint $page): array
    {
        $files = [
            'controller' => "app/Http/Controllers/{$page->className}Controller.php",
            $page->public ? 'page.public' : 'page.signed-in' => "resources/js/pages/{$page->className}.tsx",
            match (true) {
                $page->public => 'test.public',
                $page->permission !== null => 'test.permission',
                default => 'test.signed-in',
            } => "tests/Feature/{$page->className}PageTest.php",
        ];

        if ($page->help) {
            $files['help'] = "resources/help/{$page->slug}.md";
        }

        return $files;
    }

    /**
     * @param  array{created: list<string>, updated: list<string>, manual: list<string>}  $result
     */
    private function addRoute(PageBlueprint $page, array &$result): void
    {
        $file = 'routes/app.php';
        $middleware = $page->permission !== null ? "->middleware('permission:{$page->permission}')" : '';
        $route = "Route::get('{$page->path}', {$page->className}Controller::class){$middleware}->name('{$page->routeName}');";
        $import = "use App\\Http\\Controllers\\{$page->className}Controller;";
        $marker = $page->public ? self::PUBLIC_MARKER : self::SIGNED_IN_MARKER;
        $contents = File::exists($this->absolute($file)) ? File::get($this->absolute($file)) : '';

        if (! preg_match('/^([ \t]*)'.preg_quote($marker, '/').'/m', $contents, $match)) {
            $result['manual'][] = "Add to {$file}: {$route} (and {$import})";

            return;
        }

        $contents = str_replace($match[0], $match[1].$route."\n".$match[0], $contents);
        File::put($this->absolute($file), $this->withImport($contents, $import));
        $result['updated'][] = $file;
    }

    /**
     * @param  array{created: list<string>, updated: list<string>, manual: list<string>}  $result
     */
    private function addNavigationLink(PageBlueprint $page, array &$result): void
    {
        $file = 'resources/js/appNavigation.ts';
        $permission = $page->permission !== null ? ", permission: '{$page->permission}'" : '';
        $link = "{ label: '{$page->title}', href: '{$page->path}'{$permission} },";
        $contents = File::exists($this->absolute($file)) ? File::get($this->absolute($file)) : '';

        if (! preg_match('/^([ \t]*)'.preg_quote(self::NAVIGATION_MARKER, '/').'/m', $contents, $match)) {
            $result['manual'][] = "Add to {$file}: {$link}";

            return;
        }

        File::put($this->absolute($file), str_replace($match[0], $match[1].$link."\n".$match[0], $contents));
        $result['updated'][] = $file;
    }

    /**
     * Add a `use` line to the import block, keeping it in alphabetical order.
     */
    private function withImport(string $contents, string $import): string
    {
        if (str_contains($contents, $import)) {
            return $contents;
        }

        if (! preg_match('/^(?:use [^;]+;\R)+/m', $contents, $block)) {
            return preg_replace('/^<\?php\R\R?/', "<?php\n\n{$import}\n\n", $contents, 1) ?? $contents;
        }

        $imports = preg_split('/\R/', trim($block[0]));
        $imports[] = $import;
        sort($imports, SORT_STRING | SORT_FLAG_CASE);

        return str_replace($block[0], implode("\n", array_unique($imports))."\n", $contents);
    }

    private function render(string $template, PageBlueprint $page): string
    {
        $stub = File::get(__DIR__."/stubs/{$template}.stub");

        return strtr($stub, [
            '{{ class }}' => $page->className,
            '{{ title }}' => $page->title,
            '{{ path }}' => $page->path,
            '{{ routeName }}' => $page->routeName,
            '{{ permission }}' => (string) $page->permission,
            '{{ permissionLine }}' => $page->permission !== null ? "permission: {$page->permission}\n" : '',
            '{{ openStep }}' => $page->nav ? "Choose **{$page->title}** in the menu." : "Go to {$page->path} in your browser.",
        ]);
    }

    private function absolute(string $relative): string
    {
        return $this->basePath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
