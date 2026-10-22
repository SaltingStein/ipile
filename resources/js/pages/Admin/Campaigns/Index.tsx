import type { FormEvent } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import AdminLayout from '../../../components/AdminLayout';
import TemplateSketch from '../../../components/campaigns/TemplateSketch';
import type { CampaignTemplateValue } from '../../../components/campaigns/types';
import Button from '../../../components/ui/Button';
import EmptyState from '../../../components/ui/EmptyState';
import Panel from '../../../components/ui/Panel';
import TextField from '../../../components/ui/TextField';

interface CampaignRow {
    id: number;
    title: string;
    slug: string;
    template: string;
    status: 'draft' | 'published';
    has_ended: boolean;
    ends_at: string | null;
    is_home_page: boolean;
    url: string;
    views: number;
    cta_clicks: number;
    conversions: number;
    conversion_rate: number | null;
    updated_at: string | null;
}

interface CampaignsIndexProps {
    pages: CampaignRow[];
    templates: { value: CampaignTemplateValue; label: string; description: string }[];
}

export default function CampaignsIndex({ pages, templates }: CampaignsIndexProps) {
    const form = useForm<{ title: string; template: CampaignTemplateValue }>({ title: '', template: 'spotlight' });

    function create(event: FormEvent) {
        event.preventDefault();
        form.post('/admin/campaigns');
    }

    return (
        <AdminLayout title="Campaign pages">
            <Panel title="New campaign page" description="Pick a design to start from. You can change the design, colours and every word later.">
                <form onSubmit={create} className="flex flex-col gap-5">
                    <fieldset className="grid gap-4 md:grid-cols-3">
                        <legend className="mb-2 text-sm font-medium text-slate-200">Template</legend>
                        {templates.map((template) => (
                            <label key={template.value} htmlFor={`template-${template.value}`}
                                className={`flex cursor-pointer flex-col gap-3 rounded-xl border p-3 transition ${form.data.template === template.value ? 'border-emerald-500 bg-emerald-500/5' : 'border-slate-800 hover:border-slate-600'}`}>
                                <TemplateSketch template={template.value} />
                                <span className="flex items-start gap-2">
                                    <input id={`template-${template.value}`} type="radio" name="template" checked={form.data.template === template.value}
                                        onChange={() => form.setData('template', template.value)} className="mt-1 size-4 accent-emerald-500" />
                                    <span className="flex flex-col">
                                        <span className="font-semibold text-slate-100">{template.label}</span>
                                        <span className="text-xs text-slate-400">{template.description}</span>
                                    </span>
                                </span>
                            </label>
                        ))}
                    </fieldset>
                    <div className="flex flex-wrap items-end gap-4">
                        <div className="min-w-64 flex-1">
                            <TextField label="Page name" name="campaign-title" value={form.data.title} required maxLength={150}
                                hint="Used as the headline and the web address to begin with, e.g. Summer Sale 2026."
                                onChange={(title) => form.setData('title', title)} error={form.errors.title} />
                        </div>
                        <Button disabled={form.processing}>{form.processing ? 'Creating…' : 'Create page'}</Button>
                    </div>
                </form>
            </Panel>

            <Panel title="Your campaign pages" description="Views count each visitor once per visit. Sign-ups are answers sent through the page's form.">
                {pages.length === 0 ? (
                    <EmptyState title="No campaign pages yet" description="Create one above. It stays a draft until you publish it." />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[44rem] text-left text-sm">
                            <thead className="text-xs uppercase tracking-wide text-slate-400">
                                <tr>
                                    <th scope="col" className="py-2 pr-4 font-medium">Page</th>
                                    <th scope="col" className="py-2 pr-4 font-medium">Status</th>
                                    <th scope="col" className="py-2 pr-4 text-right font-medium">Views</th>
                                    <th scope="col" className="py-2 pr-4 text-right font-medium">Clicks</th>
                                    <th scope="col" className="py-2 pr-4 text-right font-medium">Sign-ups</th>
                                    <th scope="col" className="py-2 pr-4 text-right font-medium">Rate</th>
                                    <th scope="col" className="py-2 font-medium"><span className="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-800">
                                {pages.map((page) => (
                                    <tr key={page.id}>
                                        <td className="py-3 pr-4">
                                            <Link href={`/admin/campaigns/${page.id}/edit`} className="font-medium text-slate-100 hover:text-emerald-300">{page.title}</Link>
                                            <p className="text-xs text-slate-400">{page.template} · /c/{page.slug}{page.is_home_page && ' · Home page'}</p>
                                        </td>
                                        <td className="py-3 pr-4"><CampaignStatus page={page} /></td>
                                        <td className="py-3 pr-4 text-right tabular-nums">{page.views.toLocaleString()}</td>
                                        <td className="py-3 pr-4 text-right tabular-nums">{page.cta_clicks.toLocaleString()}</td>
                                        <td className="py-3 pr-4 text-right tabular-nums">{page.conversions.toLocaleString()}</td>
                                        <td className="py-3 pr-4 text-right tabular-nums">{page.conversion_rate === null ? '–' : `${page.conversion_rate}%`}</td>
                                        <td className="py-3">
                                            <div className="flex justify-end gap-3">
                                                <a href={page.url} target="_blank" rel="noreferrer" className="text-slate-300 hover:text-emerald-300">{page.status === 'published' ? 'View ↗' : 'Preview ↗'}</a>
                                                <Link href={`/admin/campaigns/${page.id}/edit`} className="text-slate-300 hover:text-emerald-300">Edit</Link>
                                                <button type="button" onClick={() => router.post(`/admin/campaigns/${page.id}/duplicate`)} className="text-slate-300 hover:text-emerald-300">Copy</button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>
        </AdminLayout>
    );
}

function CampaignStatus({ page }: { page: CampaignRow }) {
    if (page.status === 'draft') {
        return <span className="rounded-full bg-slate-800 px-2 py-0.5 text-xs text-slate-300">Draft</span>;
    }

    if (page.has_ended) {
        return <span className="rounded-full bg-amber-500/10 px-2 py-0.5 text-xs text-amber-300">Ended</span>;
    }

    return <span className="rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs text-emerald-300">Live</span>;
}
