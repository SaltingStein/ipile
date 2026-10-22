import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AdminLayout from '../../components/AdminLayout';
import Button from '../../components/ui/Button';
import TextField from '../../components/ui/TextField';
import Panel from '../../components/ui/Panel';
import type { LegalDocument } from '../../types';

interface EditorProps {
    document: LegalDocument;
    history: { id: number; action: string; user_id: number | null; title: string; body: string; version: number; created_at: string }[];
    canEdit: boolean;
    canPublish: boolean;
    placeholders: Record<string, string>;
}

function fillPlaceholders(text: string, placeholders: Record<string, string>): string {
    return Object.entries(placeholders).reduce((filled, [placeholder, value]) =>
        filled.replace(new RegExp(placeholder.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi'), () => value), text);
}

function Editor({ document, history, canEdit, canPublish, placeholders }: EditorProps) {
    const form = useForm({ title: document.title, body: document.body, revision: document.revision });
    const publisher = useForm({ revision: document.revision });
    const errors = { ...form.errors, ...publisher.errors } as Record<string, string>;
    const save = (event: FormEvent) => { event.preventDefault(); form.put(`/admin/legal/${document.slug}`, { preserveScroll: true }); };

    return <AdminLayout title={document.title}>
        <Panel title="Draft" description={document.version ? `Public version: ${document.version}. Save changes before publishing.` : 'This page is not public until it is published.'}>
            <form onSubmit={save} className="space-y-4">
                <TextField name="legal-title" label="Title" value={form.data.title} disabled={!canEdit || form.processing} required maxLength={120} onChange={(value) => form.setData('title', value)} error={errors.title} />
                <label className="block space-y-2">
                    <span className="text-sm font-medium">Content</span>
                    <textarea value={form.data.body} onChange={(event) => form.setData('body', event.target.value)}
                        disabled={!canEdit || form.processing} rows={18} maxLength={100000} required
                        className="w-full rounded-lg border border-slate-700 bg-slate-950 p-3 text-sm text-slate-100" />
                    <span className="block text-xs text-slate-400">
                        Write [service name], [organisation name] or [contact email] and they are filled in from <Link href="/admin/branding" className="text-emerald-400">Admin → Branding</Link> when the page is shown. A placeholder without a value there stays as written.
                    </span>
                </label>
                {errors.body && <p role="alert" className="text-sm text-rose-400">{errors.body}</p>}
                {errors.document && <p role="alert" className="text-sm text-rose-400">{errors.document}</p>}
                <div className="flex flex-wrap gap-3">
                    {canEdit && <Button disabled={form.processing}>{form.processing ? 'Saving…' : 'Save draft'}</Button>}
                    {canPublish && <Button type="button" variant="secondary" disabled={form.processing || publisher.processing || form.isDirty}
                        onClick={() => publisher.post(`/admin/legal/${document.slug}/publish`, { preserveScroll: true })}>Publish saved draft</Button>}
                    {document.version > 0 && <a href={`/legal/${document.slug}`} target="_blank" rel="noreferrer" className="px-3 py-2 text-sm text-emerald-400">View public page</a>}
                </div>
            </form>
        </Panel>
        <Panel title="Draft preview">
            <h2 className="text-xl font-semibold">{fillPlaceholders(form.data.title, placeholders)}</h2>
            <div className="whitespace-pre-wrap break-words text-sm leading-7 text-slate-300">{fillPlaceholders(form.data.body, placeholders)}</div>
        </Panel>
        <Panel title="Edit and publishing history" description="The latest 30 snapshots, with the actor and published version.">
            {history.length === 0 && <p className="text-sm text-slate-400">No edits yet.</p>}
            {history.map((entry) => <details key={entry.id} className="rounded-lg border border-slate-800 p-3">
                <summary className="cursor-pointer text-sm">{entry.action === 'published' ? 'Published' : 'Draft saved'} · v{entry.version} · User {entry.user_id ?? 'deleted'} · {entry.created_at}</summary>
                <h3 className="mt-4 font-semibold">{entry.title}</h3>
                <div className="mt-2 whitespace-pre-wrap break-words text-sm text-slate-400">{entry.body}</div>
            </details>)}
        </Panel>
    </AdminLayout>;
}

export default function LegalEditor(props: EditorProps) {
    return <Editor key={props.document.revision} {...props} />;
}
