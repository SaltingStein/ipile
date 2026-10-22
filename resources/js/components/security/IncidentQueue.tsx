import { Link } from '@inertiajs/react';
import RiskBadge from '../RiskBadge';
import type { SecurityIncidentSummary } from '../../pages/Admin/SecurityCenter';

export interface PaginatedIncidents {
    data: SecurityIncidentSummary[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
}

const filterLabels: Record<string, string> = { active: 'Needs attention', resolved: 'Resolved', all: 'All' };

export const verdictLabels: Record<string, string> = {
    likely_benign: 'Likely benign',
    suspicious: 'Suspicious',
    likely_compromised: 'Likely compromised',
};

/**
 * The Aegis incident queue with its status filter and pages. Used by Threat monitoring and the Incidents page.
 */
export default function IncidentQueue({ incidents, filter, filters, filterUrl, canTriage }: {
    incidents: PaginatedIncidents;
    filter: string;
    filters: string[];
    /** The page the filter links point at (it receives `?status=`). */
    filterUrl: string;
    canTriage: boolean;
}) {
    const accountLabel = (incident: SecurityIncidentSummary) => (incident.user ? incident.user.name : `Signed out · ${incident.ip_address ?? 'unknown IP'}`);

    return (
        <>
            <nav aria-label="Filter incidents" className="flex flex-wrap gap-2">
                {filters.map((option) => (
                    <Link key={option} href={`${filterUrl}?status=${option}`} preserveScroll aria-current={option === filter ? 'page' : undefined}
                        className={`rounded-full border px-3 py-1 text-sm transition ${option === filter ? 'border-emerald-500 bg-emerald-500/10 text-emerald-200' : 'border-slate-700 text-slate-300 hover:border-slate-500'}`}>
                        {filterLabels[option] ?? option}
                    </Link>
                ))}
            </nav>

            {incidents.data.length === 0 ? (
                <p className="text-sm text-slate-400">No incidents here.</p>
            ) : (
                <div className="overflow-x-auto rounded-lg border border-slate-800">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-slate-900 text-slate-400">
                            <tr>
                                <th className="px-4 py-2 font-medium">Risk</th>
                                <th className="px-4 py-2 font-medium">Account</th>
                                <th className="px-4 py-2 font-medium">Events</th>
                                <th className="px-4 py-2 font-medium">Response</th>
                                <th className="px-4 py-2 font-medium">AI verdict</th>
                                <th className="px-4 py-2 font-medium">Status</th>
                                <th className="px-4 py-2 font-medium">Last event</th>
                            </tr>
                        </thead>
                        <tbody>
                            {incidents.data.map((incident) => (
                                <tr key={incident.id} className="border-t border-slate-800 align-top">
                                    <td className="px-4 py-2"><RiskBadge score={incident.risk_score} /></td>
                                    <td className="px-4 py-2">
                                        {canTriage ? (
                                            <Link href={`/admin/security/incidents/${incident.id}`} className="font-medium text-emerald-300 hover:text-emerald-200">{accountLabel(incident)}</Link>
                                        ) : (
                                            <span className="font-medium text-slate-100">{accountLabel(incident)}</span>
                                        )}
                                        {incident.user?.email && <span className="block text-xs text-slate-400">{incident.user.email}</span>}
                                    </td>
                                    <td className="px-4 py-2 tabular-nums text-slate-300">{incident.event_count}</td>
                                    <td className="px-4 py-2 text-slate-300">
                                        {incident.action_taken?.label ?? (incident.recommended_action ? <span className="text-slate-400">Would: {incident.recommended_action.label}</span> : '—')}
                                    </td>
                                    <td className="px-4 py-2 text-slate-300">
                                        {incident.ai.analysis ? verdictLabels[incident.ai.analysis.verdict] ?? incident.ai.analysis.verdict : incident.ai.status === 'pending' ? 'Analysing…' : '—'}
                                    </td>
                                    <td className="px-4 py-2 capitalize text-slate-300">{incident.resolution ? incident.resolution.replace('_', ' ') : incident.status}</td>
                                    <td className="px-4 py-2 text-slate-400">{new Date(incident.last_event_at).toLocaleString()}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {incidents.links.length > 3 && (
                <nav aria-label="Incident pages" className="flex flex-wrap gap-1">
                    {incidents.links.map((link, index) => link.url === null
                        ? <span key={index} className="px-3 py-1 text-sm text-slate-500" dangerouslySetInnerHTML={{ __html: link.label }} />
                        : <Link key={index} href={link.url} preserveScroll aria-current={link.active ? 'page' : undefined}
                            className={`rounded-md px-3 py-1 text-sm ${link.active ? 'bg-emerald-500/10 text-emerald-200' : 'text-slate-300 hover:bg-slate-800'}`}
                            dangerouslySetInnerHTML={{ __html: link.label }} />)}
                </nav>
            )}
        </>
    );
}
