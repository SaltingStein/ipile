---
name: ipile-pages
description: "Adds or changes pages in an Ìpìlẹ̀ app: new Inertia pages, routes in routes/app.php, workspace sidebar links (appNavigation.ts), permission-gated pages, team-scoped pages, and the dashboard. Use when the developer asks for a new page, screen or view, to change the dashboard, to add something to the sidebar or menu, or to restrict a page to certain users or roles."
---

# Ìpìlẹ̀ pages

## Default path: `ipile:page`

```bash
php artisan ipile:page Reports --nav                              # signed-in page + sidebar link
php artisan ipile:page Billing --nav --permission=view_billing    # only users with the permission
php artisan ipile:page Pricing --public                           # anyone, signed in or not
php artisan ipile:page MonthlyReport --path=/reports/monthly --no-help
```

It creates `app/Http/Controllers/{Name}Controller.php` (invokable), `resources/js/pages/{Name}.tsx`, a route in `routes/app.php`, `tests/Feature/{Name}PageTest.php`, a sidebar link with `--nav`, and `resources/help/{name}.md` as a draft. It refuses (and changes nothing) when a file, route name or path is taken. If the developer removed the marker comments in `routes/app.php` or `appNavigation.ts`, it prints the line to add by hand: add it for them.

Then:
1. Put the page's data in the controller (`Inertia::render('Name', [...])`) and build the UI in the `.tsx` file using `components/ui/*`.
2. Replace the help draft's placeholder text with real end-user steps (see `ipile-help-docs`). Leave `draft: true` for the developer to remove.
3. Run `php artisan test --compact --filter={Name}PageTest` and `npm run typecheck`.

## Permissions

- New permission: add it to `RolesAndPermissionsSeeder::PERMISSIONS` (and to a role in `ROLES` if it should be granted by default), then `php artisan db:seed --class=RolesAndPermissionsSeeder`. `ipile:page` warns when the permission does not exist yet.
- Route: `->middleware('permission:view_reports')`. This is the real protection.
- UI: `<Can perform="view_reports">…</Can>` or `user.permissions.includes(...)` together with `user.is_super_admin`. Hiding is a convenience only.
- Super admins pass every check through `Gate::before`.

## Site-wide permission or team role?

Decide who the page is for before choosing the check. If the request does not make it clear, ask the developer one short question.

| Who should have access | Check | Example |
|---|---|---|
| Staff across the whole site (operators, support, finance) | A global permission: `->middleware('permission:view_billing')` | An operator report of every team's revenue |
| People inside a team, by their role in that team | `TeamPolicy` on the current team | A team's own invoices, visible to its owner and admins |
| Both | Both checks | A team page that also needs a paid-plan permission |

Global permissions are not per team (`config/permission.php` has `'teams' => false`), and only the site `admin` role gets most of them by default, so ordinary team members do not have them.

For a team-role rule, add an ability to `app/Policies/TeamPolicy.php` and reuse its helpers:

```php
/**
 * Owner and admins may see the team's invoices.
 */
public function viewInvoices(User $user, Team $team): Response
{
    return $this->managerOnly($user, $team);   // owner + team admins; ownerOnly() for owner alone
}
```

Then in the controller: `Gate::authorize('viewInvoices', $team)` with `$team = $request->user()->currentTeam`. For finer rules use `$team->roleOf($user)` (`TeamRole::Admin`, `TeamRole::Member`) and `$team->isOwnedBy($user)`. Pass `--permission` to `ipile:page` only for the global-permission case; otherwise add the policy check by hand and drop the permission from the sidebar link.

## Team-scoped pages

- Current team: `$request->user()->currentTeam` (may be null; handle it with an empty state).
- Scope queries: `->whereBelongsTo($request->user()->currentTeam)`; never trust a team id from the request without `Gate::authorize('view', $team)`.
- Team rights: `TeamPolicy` abilities `view`, `update` (owner/admins), `manageMembers`, `delete`, `transferOwnership`.

## Sidebar and admin menu

- Workspace sidebar: `resources/js/appNavigation.ts` entries `{ label, href, permission? }`.
- Admin pages go in a group of `resources/js/adminNavigation.ts` with the same permissions as the route, and use `AdminLayout`. Pick by purpose: Appearance (look, menus and languages), Post manager (blog), Forms, Campaigns (landing pages), Security (access and monitoring), Platform (connected services, messages, the site assistant), Developers (API access and docs), Legal & privacy. For product features, add a group named after the product area (e.g. "Billing") rather than squeezing them into Ìpìlẹ̀'s groups.
- Operators can add links without code in Admin → Appearance → Menus; do not duplicate those.

## Dashboard

`resources/js/pages/Dashboard.tsx` + `app/Http/Controllers/DashboardController.php` belong to the developer. Replace the example stat tiles and placeholder freely; keep the local-only checklist unless asked to remove it.

## Tests to keep

Guests are redirected to login (signed-in pages), users without the permission get 403, users with it see the component. `ipile:page` generates these; update them when the page's rules change.

## If the developer wants something different

Writing the route and page by hand is fine: route in `routes/app.php`, page wrapped in `AppLayout`/`PublicLayout`, a feature test. A different folder structure (e.g. `pages/Reports/Index.tsx`) is fine too; Inertia resolves nested names (`Inertia::render('Reports/Index')`). Keep the permission check on the route whatever the structure.
