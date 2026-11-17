import type { ProductAdminEntry } from './adminNavigation';

/**
 * Your product's admin pages. Ìpìlẹ̀ never changes this file; its own pages live in adminNavigation.ts.
 *
 * Add a group of your own, or add pages to one of Ìpìlẹ̀'s groups by naming it. Give each page the same
 * permissions as its route (`->middleware('permission:manage_invoices')`), and register a new permission with
 * `Permissions::define('manage_invoices', '…', admin: true)` so its holders can open the admin overview too.
 */
export const productAdminNavigation: ProductAdminEntry[] = [
    // A new group, shown after Ìpìlẹ̀'s groups:
    // {
    //     title: 'Billing',
    //     description: 'Invoices and refunds.',
    //     sections: [{ title: 'Invoices', href: '/admin/invoices', description: 'Every invoice and its status.', permissions: ['manage_invoices'] }],
    // },
    // Pages added to the end of one of Ìpìlẹ̀'s groups:
    // { group: 'Platform', sections: [{ title: 'Webhooks', href: '/admin/webhooks', description: 'Outgoing webhooks.', permissions: ['manage_settings'] }] },
];
