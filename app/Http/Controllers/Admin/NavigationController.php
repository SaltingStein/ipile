<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\NavigationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;

/**
 * Admin → Menus: the workspace sidebar links and the public site menu.
 */
class NavigationController extends Controller
{
    private const PATH_MESSAGE = 'Enter an internal path such as /reports. External URLs and scripts are not allowed.';

    public function edit(NavigationSettings $navigation): Response
    {
        return Inertia::render('Admin/Navigation', [
            'items' => $navigation->items(),
            'siteItems' => $navigation->siteItems(),
            'permissions' => Permission::query()->where('guard_name', 'web')->orderBy('name')->pluck('name'),
            'updateUrl' => route('admin.navigation.update'),
            'siteUpdateUrl' => route('admin.navigation.site.update'),
        ]);
    }

    public function update(Request $request, NavigationSettings $navigation): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['present', 'array', 'max:'.NavigationSettings::MAX_ITEMS],
            'items.*' => ['array:label,href,permission'],
            'items.*.label' => ['required', 'string', 'max:60'],
            'items.*.href' => ['required', 'string', 'max:2048', 'distinct', 'regex:'.NavigationSettings::PATH_PATTERN],
            'items.*.permission' => ['present', 'nullable', 'string', Rule::exists(Permission::class, 'name')->where('guard_name', 'web')],
        ], [
            'items.*.href.regex' => self::PATH_MESSAGE,
            'items.*.href.distinct' => 'Each menu item must have a different path.',
        ]);

        $navigation->update($validated['items']);

        return back()->with('status', __('Sidebar menu saved.'));
    }

    /**
     * Save the public site menu (header links on public pages).
     */
    public function updateSite(Request $request, NavigationSettings $navigation): RedirectResponse
    {
        $validated = $request->validate([
            'site_items' => ['present', 'array', 'max:'.NavigationSettings::MAX_ITEMS],
            'site_items.*' => ['array:label,href'],
            'site_items.*.label' => ['required', 'string', 'max:40'],
            'site_items.*.href' => ['required', 'string', 'max:2048', 'distinct', 'regex:'.NavigationSettings::PATH_PATTERN],
        ], [
            'site_items.*.href.regex' => self::PATH_MESSAGE,
            'site_items.*.href.distinct' => 'Each menu item must have a different path.',
        ]);

        $navigation->updateSiteItems($validated['site_items']);

        return back()->with('status', __('Site menu saved.'));
    }

    /**
     * Add one link to a menu, e.g. from the form builder's "Share" dialog.
     */
    public function addLink(Request $request, NavigationSettings $navigation): RedirectResponse
    {
        $validated = $request->validate([
            'menu' => ['required', Rule::in([NavigationSettings::MENU_SITE, NavigationSettings::MENU_WORKSPACE])],
            'label' => ['required', 'string', 'max:40'],
            'href' => ['required', 'string', 'max:2048', 'regex:'.NavigationSettings::PATH_PATTERN],
        ], ['href.regex' => self::PATH_MESSAGE]);

        $menuName = $validated['menu'] === NavigationSettings::MENU_SITE ? __('site menu') : __('workspace sidebar');

        if ($navigation->contains($validated['menu'], $validated['href'])) {
            return back()->with('status', __('Already in the :menu.', ['menu' => $menuName]));
        }

        if ($navigation->count($validated['menu']) >= NavigationSettings::MAX_ITEMS) {
            throw ValidationException::withMessages(['menu' => __('The :menu is full. Remove a link in Admin → Menus first.', ['menu' => $menuName])]);
        }

        $navigation->add($validated['menu'], $validated['label'], $validated['href']);

        return back()->with('status', __('Added to the :menu.', ['menu' => $menuName]));
    }
}
