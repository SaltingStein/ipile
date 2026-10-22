<?php

namespace App\Services\Assistant;

use App\Ai\Tools\AssistantTool;
use Closure;
use InvalidArgumentException;

/**
 * What builders added to the site assistant, through {@see AssistantDataPolicy::block()},
 * {@see AssistantMetrics::define()}, {@see AssistantTools::register()}, {@see WidgetDataTools::register()},
 * {@see AssistantGuard::rule()}, {@see AssistantIntentRouter::rule()} and {@see AssistantKnowledge::add()}.
 *
 * Append-only by design: there is no way to remove an entry, and Ìpìlẹ̀'s own blocks, figures and screening rules
 * live in code outside this registry, so an extension can add protection or abilities but never take any away.
 * Registered as a singleton; empty unless a builder adds something.
 */
class AssistantExtensions
{
    private const NAME = '/^[a-z][a-z0-9_]*$/';

    /** @var list<string> */
    private array $blockedTables = [];

    /** @var array<string, list<string>> */
    private array $protectedColumns = [];

    /** @var array<string, array{label: string, description: string, permission: string, values: Closure(): array<mixed>}> */
    private array $metrics = [];

    /** @var list<class-string<AssistantTool>> */
    private array $tools = [];

    /** @var array<class-string<AssistantTool>, list<string>|null> class => mode values, null for every mode */
    private array $toolModes = [];

    /** @var list<class-string<AssistantTool>> */
    private array $widgetTools = [];

    /** @var array<string, list<string>> intent => regular expressions */
    private array $intentRules = [];

    /** @var list<string> Markdown files or globs for the staff guidance mode */
    private array $knowledge = [];

    /** @var array<string, list<string>> category => regular expressions */
    private array $rules = [];

    /**
     * @param  list<string>  $columns
     */
    public function block(string $table, array $columns = []): void
    {
        $this->assertName($table, 'table');

        if ($columns === []) {
            $this->blockedTables = array_values(array_unique([...$this->blockedTables, $table]));

            return;
        }

        foreach ($columns as $column) {
            $this->assertName($column, 'column');
        }

        $this->protectedColumns[$table] = array_values(array_unique([...($this->protectedColumns[$table] ?? []), ...$columns]));
    }

    /**
     * @return list<string>
     */
    public function blockedTables(): array
    {
        return $this->blockedTables;
    }

    /**
     * @return array<string, list<string>>
     */
    public function protectedColumns(): array
    {
        return $this->protectedColumns;
    }

    /**
     * @param  Closure(): array<mixed>  $values
     */
    public function defineMetric(string $key, string $label, string $description, string $permission, Closure $values): void
    {
        if (preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $key) !== 1) {
            throw new InvalidArgumentException("Assistant figure keys look like [area.figure]; [{$key}] does not.");
        }

        if (isset($this->metrics[$key])) {
            throw new InvalidArgumentException("The assistant figure [{$key}] is already defined.");
        }

        $this->metrics[$key] = ['label' => $label, 'description' => $description, 'permission' => $permission, 'values' => $values];
    }

    /**
     * @return array<string, array{label: string, description: string, permission: string, values: Closure(): array<mixed>}>
     */
    public function metrics(): array
    {
        return $this->metrics;
    }

    /**
     * Registering a tool again only widens the modes it is offered in (append-only).
     *
     * @param  class-string<AssistantTool>  $class
     * @param  list<AssistantIntent>|null  $modes  Null offers it in every mode.
     */
    public function registerTool(string $class, ?array $modes = null): void
    {
        if (! is_subclass_of($class, AssistantTool::class)) {
            throw new InvalidArgumentException("[{$class}] must extend ".AssistantTool::class.', so it runs under the assistant data policy.');
        }

        if ($modes !== null && ($modes === [] || array_filter($modes, fn (mixed $mode) => ! $mode instanceof AssistantIntent) !== [])) {
            throw new InvalidArgumentException('Assistant tool modes must be a non-empty list of '.AssistantIntent::class.' cases, or null for every mode.');
        }

        if (! in_array($class, $this->tools, true)) {
            $this->tools[] = $class;
            $this->toolModes[$class] = $modes === null ? null : array_map(fn (AssistantIntent $mode) => $mode->value, $modes);

            return;
        }

        if ($this->toolModes[$class] !== null) {
            $this->toolModes[$class] = $modes === null ? null : array_values(array_unique([...$this->toolModes[$class], ...array_map(fn (AssistantIntent $mode) => $mode->value, $modes)]));
        }
    }

    /**
     * Every registered tool, whatever its modes.
     *
     * @return list<class-string<AssistantTool>>
     */
    public function tools(): array
    {
        return $this->tools;
    }

    /**
     * The registered tools offered in this mode.
     *
     * @return list<class-string<AssistantTool>>
     */
    public function toolsFor(AssistantIntent $mode): array
    {
        return array_values(array_filter($this->tools, fn (string $class) => $this->toolModes[$class] === null || in_array($mode->value, $this->toolModes[$class], true)));
    }

    public function addIntentRule(string $intent, string $pattern): void
    {
        if (AssistantIntent::tryFrom($intent) === null) {
            throw new InvalidArgumentException("Unknown assistant intent [{$intent}].");
        }

        if (@preg_match($pattern, '') === false) {
            throw new InvalidArgumentException("[{$pattern}] is not a valid regular expression.");
        }

        $this->intentRules[$intent][] = $pattern;
    }

    /**
     * @return array<string, list<string>> intent => regular expressions
     */
    public function intentRules(): array
    {
        return $this->intentRules;
    }

    public function addKnowledge(string $path): void
    {
        if (trim($path) === '') {
            throw new InvalidArgumentException('Give the path of a Markdown file, or a glob such as docs/*.md.');
        }

        if (! in_array($path, $this->knowledge, true)) {
            $this->knowledge[] = $path;
        }
    }

    /**
     * @return list<string>
     */
    public function knowledge(): array
    {
        return $this->knowledge;
    }

    /**
     * @param  class-string<AssistantTool>  $class
     */
    public function registerWidgetTool(string $class): void
    {
        if (! is_subclass_of($class, AssistantTool::class)) {
            throw new InvalidArgumentException("[{$class}] must extend ".AssistantTool::class.', so it runs under the assistant data policy.');
        }

        if (! in_array($class, $this->widgetTools, true)) {
            $this->widgetTools[] = $class;
        }
    }

    /**
     * @return list<class-string<AssistantTool>>
     */
    public function widgetTools(): array
    {
        return $this->widgetTools;
    }

    public function addRule(string $category, string $pattern): void
    {
        if (! in_array($category, [AssistantVerdict::CREDENTIALS, AssistantVerdict::OTHER_PEOPLE, AssistantVerdict::SYSTEM_INTERNALS], true)) {
            throw new InvalidArgumentException("Unknown screening category [{$category}]; use one of AssistantVerdict's categories.");
        }

        if (@preg_match($pattern, '') === false) {
            throw new InvalidArgumentException("[{$pattern}] is not a valid regular expression.");
        }

        $this->rules[$category][] = $pattern;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    private function assertName(string $name, string $kind): void
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new InvalidArgumentException("[{$name}] is not a valid {$kind} name.");
        }
    }
}
