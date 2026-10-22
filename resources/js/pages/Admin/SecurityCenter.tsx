import { Link } from '@inertiajs/react';
import AdminLayout from '../../components/AdminLayout';
import Panel from '../../components/ui/Panel';
import RiskBadge from '../../components/RiskBadge';
import IncidentQueue, { verdictLabels } from '../../components/security/IncidentQueue';
import type { PaginatedIncidents } from '../../components/security/IncidentQueue';
import StatTile from '../../components/ui/StatTile';

export interface SecurityActionSummary {
    value: string;
    label: string;
}

export interface SecurityIncidentSummary {
    id: number;
    user: { id: number; name: string; email: string | null; status?: string } | null;
    ip_address: string | null;
    status: string;
    resolution: string | null;
    resolution_note: string | null;
    risk_score: number;
    risk_level: string;
    recommended_action: SecurityActionSummary | null;
    action_taken: SecurityActionSummary | null;
    event_count: number;
    first_event_at: string;
    last_event_at: string;
    resolved_at: string | null;
    resolved_by: string | null;
    ai: {
        status: string | null;
        analyzed_at: string | null;
        analysis: {
            verdict: string;
            confidence: number;
            summary: string;
            explanation: string;
            recommended_action: string;
            suggested_steps: string[];
            model: string;
        } | null;
        error: string | null;
    };
}

export interface SecurityEventRow {
    id: number;
    type: string;
    label: string;
    category: string;
    user: { id: number; name: string; email: string | null } | null;
    ip_address: string | null;
    device: string | null;
    context: Record<string, string | number | boolean | null>;
    risk_score: number;
    posture_score: number;
    signals: { key: string; label: string; score: number; weight: number; detail: string | null }[];
    recommended_action: SecurityActionSummary | null;
    action_taken: SecurityActionSummary | null;
    incident_id: number | null;
    occurred_at: string;
}

interface SecurityCenterProps {
    mode: { value: string; label: string; description: string };
    filter: string;
    filters: string[];
    stats: {
        events_24h: number;
        risky_events_24h: number;
        active_incidents: number;
        accounts_at_risk: number;
        baseline_scopes: number;
        last_trained_at: string | null;
    };
    incidents: PaginatedIncidents;
    recentEvents: SecurityEventRow[];
    canManage: boolean;
    canTriage: boolean;
    urls: { index: string; settings: string; guide: string };
}

// Kept here too for pages that already import it from Threat monitoring.
export { verdictLabels };

export default function SecurityCenter({ mode, filter, filters, stats, incidents, recentEvents, canManage, canTriage, urls }: SecurityCenterProps) {
    const modeClasses = mode.value === 'off'
        ? 'border-rose-800 bg-rose-950/40 text-rose-100'
        : mode.value === 'monitor'
            ? 'border-amber-800 bg-amber-950/30 text-amber-100'
            : 'border-emerald-800 bg-emerald-950/30 text-emerald-100';

    return (
        <AdminLayout title="Threat monitoring">
            <div className={`flex flex-wrap items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm ${modeClasses}`}>
                <p><span className="font-semibold">Mode: {mode.label}.</span> {mode.description}</p>
                <div className="flex gap-4">
                    {canManage && <Link href={urls.settings} className="font-medium underline-offset-4 hover:underline">Monitoring settings</Link>}
                    <Link href={urls.guide} className="font-medium underline-offset-4 hover:underline">Monitoring guide</Link>
                </div>
            </div>

            <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile label="Events (24 h)" value={stats.events_24h} />
                <StatTile label="Risky events (24 h)" value={stats.risky_events_24h} />
                <StatTile label="Incidents needing attention" value={stats.active_incidents} />
                <StatTile label="Accounts at elevated risk" value={stats.accounts_at_risk} />
            </dl>
            <p className="text-xs text-slate-400">
                Markov baselines: {stats.baseline_scopes} {stats.baseline_scopes === 1 ? 'scope' : 'scopes'}
                {stats.last_trained_at ? `, trained ${new Date(stats.last_trained_at).toLocaleString()}.` : ', never trained (run php artisan security:train).'}
            </p>

            <Panel title="Incidents" description="Risky events grouped per account (or per IP address when nobody was signed in).">
                <IncidentQueue incidents={incidents} filter={filter} filters={filters} filterUrl={urls.index} canTriage={canTriage} />
                <Link href="/admin/security/incidents" className="self-start text-sm text-emerald-300 hover:text-emerald-200">Open the incidents page →</Link>
            </Panel>

            <Panel title="Recent elevated events" description="The latest events scoring 30 or more, whether or not they opened an incident.">
                {recentEvents.length === 0 ? (
                    <p className="text-sm text-slate-400">Nothing elevated recently.</p>
                ) : (
                    <ul className="flex flex-col divide-y divide-slate-800">
                        {recentEvents.map((event) => (
                            <li key={event.id} className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                                <span className="flex flex-col gap-0.5">
                                    <span className="text-slate-100">{event.label} <span className="text-slate-400">· {event.user?.name ?? 'Signed out'}</span></span>
                                    <span className="text-slate-400">
                                        {event.signals.map((signal) => signal.label).join(', ') || 'No signals'} · {event.device ?? 'Unknown device'} · {event.ip_address ?? 'Unknown IP'} · {new Date(event.occurred_at).toLocaleString()}
                                    </span>
                                </span>
                                <span className="flex items-center gap-3">
                                    <RiskBadge score={Math.max(event.risk_score, event.posture_score)} />
                                    {canTriage && event.incident_id && <Link href={`${urls.index}/incidents/${event.incident_id}`} className="text-emerald-300 hover:text-emerald-200">Incident</Link>}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </AdminLayout>
    );
}
