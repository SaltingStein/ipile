import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AdminLayout from '../../components/AdminLayout';
import Button from '../../components/ui/Button';
import TextField from '../../components/ui/TextField';
import Panel from '../../components/ui/Panel';
import type { NavigationItem } from '../../types';

interface NavigationProps {
    items: NavigationItem[];
    permissions: string[];
    updateUrl: string;
    siteItems: SiteMenuItem[];
    siteUpdateUrl: string;
}

export default function Navigation({ items, permissions, updateUrl, siteItems, siteUpdateUrl }: NavigationProps) {
    const form = useForm({ items });
    const errors = form.errors as Record<string, string | undefined>;
    const changeItem = (index: number, key: keyof NavigationItem, value: string) => {
        form.setData('items', form.data.items.map((item, position) => position === index ? { ...item, [key]: value } : item));
    };
    const moveItem = (index: number, direction: number) => {
        const next = [...form.data.items];
        [next[index], next[index + direction]] = [next[index + direction], next[index]];
        form.setData('items', next);
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(updateUrl, { preserveScroll: true });
    };

    return (
        <AdminLayout title="Menus">
            <SiteMenuPanel items={siteItems} updateUrl={siteUpdateUrl} />
            <Panel title="Workspace sidebar" description="Links signed-in people see in the workspace sidebar. A link points to a page; it does not create one.">
                <form onSubmit={submit} className="space-y-6">
                    <p className="text-sm text-slate-400">Add up to 20 internal links. Restrict a link to users with a permission when needed; the destination still enforces its own access rules.</p>
                    {errors.items && <p role="alert" className="text-sm text-rose-400">{errors.items}</p>}
                    {form.data.items.length === 0 && <p className="rounded-lg border border-dashed border-slate-700 p-6 text-center text-sm text-slate-400">No custom links yet. Add your first menu item below.</p>}
                    {form.data.items.map((item, index) => (
                        <fieldset key={index} disabled={form.processing} className="space-y-4 rounded-xl border border-slate-800 p-4">
                            <legend className="px-2 text-sm font-medium text-slate-300">Menu item {index + 1}</legend>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <TextField name={`menu-label-${index}`} label="Label" value={item.label} required maxLength={60}
                                    onChange={(value) => changeItem(index, 'label', value)} error={errors[`items.${index}.label`]} />
                                <TextField name={`menu-path-${index}`} label="Page path" value={item.href} required maxLength={2048}
                                    placeholder="/reports" hint="Internal path, for example /reports."
                                    onChange={(value) => changeItem(index, 'href', value)} error={errors[`items.${index}.href`]} />
                            </div>
                            <div className="space-y-2">
                                <label htmlFor={`menu-permission-${index}`} className="block text-sm font-medium text-slate-200">Visible to</label>
                                <select id={`menu-permission-${index}`} value={item.permission ?? ''}
                                    onChange={(event) => changeItem(index, 'permission', event.target.value)}
                                    aria-invalid={!!errors[`items.${index}.permission`]}
                                    className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100">
                                    <option value="">All signed-in users, including guests</option>
                                    {permissions.map((permission) => <option key={permission} value={permission}>Users with {permission}</option>)}
                                </select>
                                {errors[`items.${index}.permission`] && <p role="alert" className="text-sm text-rose-400">{errors[`items.${index}.permission`]}</p>}
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button type="button" variant="secondary" disabled={index === 0 || form.processing} onClick={() => moveItem(index, -1)} aria-label={`Move menu item ${index + 1} up`}>Move up</Button>
                                <Button type="button" variant="secondary" disabled={index === form.data.items.length - 1 || form.processing} onClick={() => moveItem(index, 1)} aria-label={`Move menu item ${index + 1} down`}>Move down</Button>
                                <Button type="button" variant="danger" disabled={form.processing} onClick={() => form.setData('items', form.data.items.filter((_, position) => position !== index))} aria-label={`Remove menu item ${index + 1}`}>Remove</Button>
                            </div>
                        </fieldset>
                    ))}
                    <div className="flex flex-wrap gap-3">
                        <Button type="button" variant="secondary" disabled={form.processing || form.data.items.length >= 20}
                            onClick={() => form.setData('items', [...form.data.items, { label: '', href: '', permission: null }])}>Add menu item</Button>
                        <Button disabled={form.processing}>{form.processing ? 'Saving…' : 'Save menu'}</Button>
                    </div>
                </form>
            </Panel>
        </AdminLayout>
    );
}

interface SiteMenuItem {
    label: string;
    href: string;
}

/**
 * Header links on every public page (landing, legal pages, forms, blog). Blog is added automatically while it is switched on.
 */
function SiteMenuPanel({ items, updateUrl }: { items: SiteMenuItem[]; updateUrl: string }) {
    const form = useForm({ site_items: items });
    const errors = form.errors as Record<string, string | undefined>;
    const links = form.data.site_items;
    const change = (index: number, key: keyof SiteMenuItem, value: string) => {
        form.setData('site_items', links.map((item, position) => (position === index ? { ...item, [key]: value } : item)));
    };
    const move = (index: number, direction: number) => {
        const next = [...links];
        [next[index], next[index + direction]] = [next[index + direction], next[index]];
        form.setData('site_items', next);
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(updateUrl, { preserveScroll: true });
    };

    return (
        <Panel title="Public site menu" description="Links in the header of every public page, for example a published registration form. Blog appears here automatically while the blog is on.">
            <form onSubmit={submit} className="space-y-4">
                {errors.site_items && <p role="alert" className="text-sm text-rose-400">{errors.site_items}</p>}
                {links.length === 0 && <p className="rounded-lg border border-dashed border-slate-700 p-6 text-center text-sm text-slate-400">No site menu links yet. Add one below, or use “Add to site menu” when you publish a form.</p>}
                {links.map((item, index) => (
                    <fieldset key={index} disabled={form.processing} className="grid gap-3 rounded-xl border border-slate-800 p-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                        <legend className="sr-only">Site menu link {index + 1}</legend>
                        <TextField name={`site-label-${index}`} label="Label" value={item.label} required maxLength={40}
                            onChange={(value) => change(index, 'label', value)} error={errors[`site_items.${index}.label`]} />
                        <TextField name={`site-path-${index}`} label="Page path" value={item.href} required maxLength={2048} placeholder="/f/summit-registration"
                            onChange={(value) => change(index, 'href', value)} error={errors[`site_items.${index}.href`]} />
                        <div className="flex gap-2">
                            <Button type="button" variant="secondary" disabled={index === 0} onClick={() => move(index, -1)} aria-label={`Move site link ${index + 1} up`}>↑</Button>
                            <Button type="button" variant="secondary" disabled={index === links.length - 1} onClick={() => move(index, 1)} aria-label={`Move site link ${index + 1} down`}>↓</Button>
                            <Button type="button" variant="danger" onClick={() => form.setData('site_items', links.filter((_, position) => position !== index))} aria-label={`Remove site link ${index + 1}`}>✕</Button>
                        </div>
                    </fieldset>
                ))}
                <div className="flex flex-wrap gap-3">
                    <Button type="button" variant="secondary" disabled={form.processing || links.length >= 20}
                        onClick={() => form.setData('site_items', [...links, { label: '', href: '' }])}>Add link</Button>
                    <Button disabled={form.processing}>{form.processing ? 'Saving…' : 'Save site menu'}</Button>
                </div>
            </form>
        </Panel>
    );
}
