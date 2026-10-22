<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Services\Blog\BlogSettings;

/**
 * Admin-managed menus, stored in the dashboard settings store: links in the signed-in workspace sidebar, and
 * links in the header of every public page (landing, legal, forms, blog).
 */
class NavigationSettings
{
    public const KEY = 'dashboard.navigation_items';

    public const SITE_KEY = 'site.menu_items';

    public const MAX_ITEMS = 20;

    public const MENU_WORKSPACE = 'workspace';

    public const MENU_SITE = 'site';

    /**
     * Internal paths only, such as /reports or /f/summit?ref=menu. External URLs and scripts are rejected.
     */
    public const PATH_PATTERN = '~\A/(?!/)[a-zA-Z0-9/_.-]*(?:\?[a-zA-Z0-9_=&.-]*)?(?:\#[a-zA-Z0-9_-]*)?\z~';

    /**
     * @return list<array{label: string, href: string, permission: ?string}>
     */
    public function items(): array
    {
        return $this->read(self::KEY);
    }

    /**
     * @return list<array{label: string, href: string, permission: ?string}>
     */
    public function forUser(User $user): array
    {
        return array_values(array_filter(
            $this->items(),
            fn (array $item) => empty($item['permission']) || $user->can($item['permission']),
        ));
    }

    /**
     * @param  list<array{label: string, href: string, permission: ?string}>  $items
     */
    public function update(array $items): void
    {
        Setting::store(self::KEY, json_encode(array_values($items), JSON_THROW_ON_ERROR));
    }

    /**
     * Links an admin added to the public site menu.
     *
     * @return list<array{label: string, href: string}>
     */
    public function siteItems(): array
    {
        return array_map(fn (array $item) => ['label' => $item['label'], 'href' => $item['href']], $this->read(self::SITE_KEY));
    }

    /**
     * The public site menu as visitors see it: the admin's links, then Blog while the blog is switched on.
     *
     * @return list<array{label: string, href: string}>
     */
    public function siteMenu(): array
    {
        $items = $this->siteItems();
        $blog = app(BlogSettings::class);

        if ($blog->enabled() && ! in_array('/blog', array_column($items, 'href'), true)) {
            $items[] = ['label' => $blog->title(), 'href' => '/blog'];
        }

        return $items;
    }

    /**
     * @param  list<array{label: string, href: string}>  $items
     */
    public function updateSiteItems(array $items): void
    {
        Setting::store(self::SITE_KEY, json_encode(array_values($items), JSON_THROW_ON_ERROR));
    }

    /**
     * Whether a menu already links to the path.
     */
    public function contains(string $menu, string $href): bool
    {
        $items = $menu === self::MENU_SITE ? $this->siteItems() : $this->items();

        return in_array($href, array_column($items, 'href'), true);
    }

    /**
     * Add one link to the end of a menu (used by "Add to menu" buttons, e.g. after publishing a form).
     *
     * @return bool false when the link was already there
     */
    public function add(string $menu, string $label, string $href): bool
    {
        if ($this->contains($menu, $href)) {
            return false;
        }

        if ($menu === self::MENU_SITE) {
            $this->updateSiteItems([...$this->siteItems(), ['label' => $label, 'href' => $href]]);
        } else {
            $this->update([...$this->items(), ['label' => $label, 'href' => $href, 'permission' => null]]);
        }

        return true;
    }

    /**
     * Number of links in a menu, to enforce MAX_ITEMS.
     */
    public function count(string $menu): int
    {
        return count($menu === self::MENU_SITE ? $this->siteItems() : $this->items());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function read(string $key): array
    {
        $items = json_decode(Setting::valueOf($key, '[]'), true);

        return is_array($items) ? array_values($items) : [];
    }
}
