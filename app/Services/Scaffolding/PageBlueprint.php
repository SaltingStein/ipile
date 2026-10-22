<?php

namespace App\Services\Scaffolding;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * What `ipile:page` should create: names, URL and access rules for one new page.
 */
final readonly class PageBlueprint
{
    public string $className;

    public string $title;

    public string $path;

    public string $routeName;

    public string $slug;

    public function __construct(
        string $name,
        ?string $path = null,
        public ?string $permission = null,
        public bool $public = false,
        public bool $nav = false,
        public bool $help = true,
    ) {
        $this->className = Str::studly(preg_replace('/Page$/', '', trim($name)) ?? '');

        if (! preg_match('/\A[A-Z][A-Za-z0-9]{0,60}\z/', $this->className)) {
            throw new InvalidArgumentException('Use a name made of letters and numbers, starting with a letter, e.g. Reports or TeamReports.');
        }

        $this->slug = Str::kebab($this->className);
        $this->title = Str::ucfirst(str_replace('-', ' ', $this->slug));
        $this->routeName = $this->slug;
        $this->path = '/'.ltrim($path ?? $this->slug, '/');

        if (! preg_match('#\A/[a-z0-9][a-z0-9\-/]*\z#', $this->path) || str_contains($this->path, '//')) {
            throw new InvalidArgumentException('Use a path of lowercase letters, numbers, dashes and slashes, e.g. /reports or /reports/monthly.');
        }

        if ($permission !== null && ! preg_match('/\A[a-z0-9_.-]{1,100}\z/', $permission)) {
            throw new InvalidArgumentException('Use a permission name of lowercase letters, numbers, dots, dashes and underscores, e.g. view_reports.');
        }

        if ($public && $permission !== null) {
            throw new InvalidArgumentException('A public page cannot require a permission. Drop --public or --permission.');
        }
    }
}
