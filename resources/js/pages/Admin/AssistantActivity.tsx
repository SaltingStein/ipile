import type { FormEvent } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '../../components/AdminLayout';
import Button from '../../components/ui/Button';
import Panel from '../../components/ui/Panel';
import TextField from '../../components/ui/TextField';
import { useState } from 'react';

export interface InteractionSummary {
    id: number;
    created_at: string | null;
    person: { id: number; name: string; email: string | null } | null;
    roles: string[];
    channel: string;
    mode: string | null;
    outcome: string;
    outcome_label: string;
    provider: string | null;
    model: string | null;
    tool_count: number;
    duration_ms: number | null;
}

interface Filters {
    person: string;
    role: string;
    mode: string;
    outcome: string;
    from: string;
    to: string;
}

interface AssistantActivityProps {
    interactions: { data: InteractionSummary[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    filters: Filters;
    roles: string[];
    modes: { value: string; label: string }[];
    outcomes: { value: string; label: string }[];
    retentionDays: number;
}

export const outcomeTone: Record<string, string> = {
    answered: 'text-emerald-300',
    denied_by_scope: 'text-amber-200',
    rate_limited: 'text-amber-200',
    refused: 'text-rose-300',
    provider_error: 'text-slate-300',
};

/**
 * Admin → Platform → Assistant activity: the append-only log of site assistant requests (CORE-913).
 */
export default function AssistantActivity({ interactions, filters, roles, modes, outcomes, retentionDays }: AssistantActivityProps) {
    const [values, setValues] = useState<Filters>(filters);

    function apply(event: FormEvent) {
        event.preventDefault();
        router.get('/admin/assistant/activity', Object.fromEntries(Object.entries(values).filter(([, value]) => value !== '')), { preserveScroll: true });
    }

    function select(name: keyof Filters, label: string, options: { value: string; label: string }[]) {
        return (
            <div className="flex flex-col gap-1.5">
                <label htmlFor={`activity-${name}`} className="text-sm font-medium text-slate-200">{label}</label>
                <select id={`activity-${name}`} value={values[name]} onChange={(event) => setValues({ ...values, [name]: event.target.value })}
                    className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100">
                    <option value="">Any</option>
                    {options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                </select>
            </div>
        );
    }

    return (
        <AdminLayout title="Assistant activity">
            <Panel title="Filter" description={`Every request to the built-in assistant, with what the person was allowed at the time. Entries are kept for ${retentionDays} days.`}>
                <form onSubmit={apply} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <TextField label="Person (name or email)" name="activity-person" value={values.person} onChange={(person) => setValues({ ...values, person })} />
                    {select('role', 'Role at the time', roles.map((role) => ({ value: role, label: role })))}
                    {select('mode', 'Mode', modes)}
                    {select('outcome', 'Outcome', outcomes)}
                    <TextField label="From" name="activity-from" type="date" value={values.from} onChange={(from) => setValues({ ...values, from })} />
                    <TextField label="To" name="activity-to" type="date" value={values.to} onChange={(to) => setValues({ ...values, to })} />
                    <div className="flex gap-2 sm:col-span-2 lg:col-span-3">
                        <Button>Apply filters</Button>
                        <Link href="/admin/assistant/activity" className="rounded-lg px-4 py-2 text-sm text-slate-300 hover:text-slate-100">Clear</Link>
                    </div>
                </form>
            </Panel>

            <Panel title={`Requests (${interactions.total})`} description="Open a request to see the question, the answer and the tools used.">
                {interactions.data.length === 0 ? (
                    <p className="text-sm text-slate-400">No requests match.</p>
                ) : (
                    <div className="overflow-x-auto rounded-lg border border-slate-800">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-slate-900 text-slate-400">
                                <tr>
                                    <th className="px-4 py-2 font-medium">When</th>
                                    <th className="px-4 py-2 font-medium">Person</th>
                                    <th className="px-4 py-2 font-medium">Roles</th>
                                    <th className="px-4 py-2 font-medium">Where</th>
                                    <th className="px-4 py-2 font-medium">Mode</th>
                                    <th className="px-4 py-2 font-medium">Outcome</th>
                                    <th className="px-4 py-2 font-medium">Tools</th>
                                </tr>
                            </thead>
                            <tbody>
                                {interactions.data.map((interaction) => (
                                    <tr key={interaction.id} className="border-t border-slate-800 align-top">
                                        <td className="px-4 py-2 text-slate-300">
                                            <Link href={`/admin/assistant/activity/${interaction.id}`} className="text-emerald-300 hover:text-emerald-200">
                                                {interaction.created_at ? new Date(interaction.created_at).toLocaleString() : `#${interaction.id}`}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-2">
                                            {interaction.person ? <>{interaction.person.name}<span className="block text-xs text-slate-400">{interaction.person.email}</span></> : <span className="text-slate-400">Deleted account</span>}
                                        </td>
                                        <td className="px-4 py-2 text-slate-300">{interaction.roles.join(', ') || '—'}</td>
                                        <td className="px-4 py-2 capitalize text-slate-300">{interaction.channel}</td>
                                        <td className="px-4 py-2 text-slate-300">{interaction.mode ?? '—'}</td>
                                        <td className={`px-4 py-2 ${outcomeTone[interaction.outcome] ?? 'text-slate-300'}`}>{interaction.outcome_label}</td>
                                        <td className="px-4 py-2 tabular-nums text-slate-300">{interaction.tool_count}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {interactions.links.length > 3 && (
                    <nav aria-label="Pages" className="flex flex-wrap gap-1">
                        {interactions.links.map((link, index) => link.url
                            ? <Link key={index} href={link.url} preserveScroll aria-current={link.active ? 'page' : undefined}
                                className={`rounded-md px-3 py-1 text-sm ${link.active ? 'bg-emerald-500/20 text-emerald-200' : 'text-slate-300 hover:bg-slate-800'}`}
                                dangerouslySetInnerHTML={{ __html: link.label }} />
                            : <span key={index} className="px-3 py-1 text-sm text-slate-600" dangerouslySetInnerHTML={{ __html: link.label }} />)}
                    </nav>
                )}
            </Panel>
        </AdminLayout>
    );
}
