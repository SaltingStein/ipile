/**
 * Your app's links in the workspace sidebar, shown under Dashboard in this order.
 * Add an entry when you add a page in routes/app.php (`php artisan ipile:page Reports --nav` does it for you).
 * Set `permission` to show a link only to users who have it; this only hides the link, so protect the route
 * itself too (e.g. ->middleware('permission:view_reports')). Operators can add extra links in Admin → Menus.
 */
export interface AppNavigationItem {
    label: string;
    href: string;
    permission?: string;
}

export const appNavigation: AppNavigationItem[] = [
    // Example: { label: 'Billing', href: '/billing', permission: 'view_billing' },
    // ipile:page adds links above this line.
];
