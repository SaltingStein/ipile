import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import AdminLayout from '../../components/AdminLayout';
import EmptyState from '../../components/ui/EmptyState';
import Panel from '../../components/ui/Panel';

interface AuditEntry {
    id: number;
    at: string | null;
    actor: string;
    action: string;
    subject: string | null;
    changes: { before?: Record<string, unknown>; after?: Record<string, unknown> } | null;
    ip_address: string | null;
}

interface AuditLogProps {
    entries: {
        data: AuditEntry[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters: { action: string | null };
}

function formatValue(value: unknown): string {
    if (value === null || value === undefined) {
        return '—';
    }

    return typeof value === 'string' ? value : JSON.stringify(value);
}

function ChangeList({ changes }: { changes: AuditEntry['changes'] }) {
    if (!changes) {
        return <span className="text-slate-500">—</span>;
    }

    const keys = [...new Set([...Object.keys(changes.before ?? {}), ...Object.keys(changes.after ?? {})])];

    return (
        <ul className="flex flex-col gap-0.5">
            {keys.map((key) => (
                <li key={key} className="break-all">
                    <span className="text-slate-400">{key}: </span>
                    {changes.before && key in changes.before && (
                        <>
                            <span className="text-rose-300 line-through">{formatValue(changes.before[key])}</span>
                            <span className="text-slate-500"> → </span>
                        </>
                    )}
                    <span className="text-slate-200">{formatValue(changes.after?.[key])}</span>
                </li>
            ))}
        </ul>
    );
}

/**
 * Admin → Security → Audit log: an append-only record of who changed what.
 * Personal details show as "[redacted]": the log records that they changed, not what they were.
 */
export default function AuditLog({ entries, filters }: AuditLogProps) {
    const [action, setAction] = useState(filters.action ?? '');

    function search(event: FormEvent) {
        event.preventDefault();
        router.get('/admin/audit-log', action ? { action } : {}, { preserveState: true, replace: true });
    }

    return (
        <AdminLayout title="Audit log">
            <Panel
                title="Audit log"
                description="Changes to accounts, roles, settings, integrations, payments, teams and legal pages, newest first. Entries cannot be edited or deleted. Personal details are recorded as changed but not shown."
            >
                <form onSubmit={search} className="flex flex-wrap items-center gap-2">
                    <label htmlFor="audit-action" className="text-sm text-slate-400">Action starts with</label>
                    <input
                        id="audit-action"
                        value={action}
                        onChange={(event) => setAction(event.target.value)}
                        placeholder="e.g. user. or setting."
                        className="rounded-md border border-slate-700 bg-slate-950 px-3 py-1.5 text-sm"
                    />
                    <button type="submit" className="rounded-md bg-slate-800 px-3 py-1.5 text-sm hover:bg-slate-700">Filter</button>
                </form>

                {entries.data.length === 0 ? (
                    <EmptyState title="Nothing recorded yet" description="Changes to accounts, roles and settings will appear here." />
                ) : (
                    <div className="overflow-x-auto rounded-lg border border-slate-800">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-slate-900 text-slate-400">
                                <tr>
                                    <th className="px-4 py-2 font-medium">When</th>
                                    <th className="px-4 py-2 font-medium">Who</th>
                                    <th className="px-4 py-2 font-medium">Action</th>
                                    <th className="px-4 py-2 font-medium">Record</th>
                                    <th className="px-4 py-2 font-medium">Change</th>
                                    <th className="px-4 py-2 font-medium">IP address</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-800">
                                {entries.data.map((entry) => (
                                    <tr key={entry.id} className="align-top">
                                        <td className="whitespace-nowrap px-4 py-2 tabular-nums text-slate-300">{entry.at ? new Date(entry.at).toLocaleString() : '—'}</td>
                                        <td className="px-4 py-2 text-slate-200">{entry.actor}</td>
                                        <td className="px-4 py-2 font-mono text-xs text-emerald-300">{entry.action}</td>
                                        <td className="px-4 py-2 text-slate-300">{entry.subject ?? '—'}</td>
                                        <td className="px-4 py-2 font-mono text-xs"><ChangeList changes={entry.changes} /></td>
                                        <td className="px-4 py-2 tabular-nums text-slate-400">{entry.ip_address ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {entries.links.length > 3 && (
                    <nav aria-label="Audit log pages" className="flex flex-wrap gap-1">
                        {entries.links.map((link, index) => link.url === null
                            ? <span key={index} className="px-3 py-1 text-sm text-slate-500" dangerouslySetInnerHTML={{ __html: link.label }} />
                            : <Link key={index} href={link.url} preserveScroll aria-current={link.active ? 'page' : undefined}
                                className={`rounded-md px-3 py-1 text-sm ${link.active ? 'bg-emerald-500/10 text-emerald-200' : 'text-slate-300 hover:bg-slate-800'}`}
                                dangerouslySetInnerHTML={{ __html: link.label }} />)}
                    </nav>
                )}
            </Panel>
        </AdminLayout>
    );
}
