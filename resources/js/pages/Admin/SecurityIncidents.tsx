import { Link } from '@inertiajs/react';
import AdminLayout from '../../components/AdminLayout';
import IncidentQueue from '../../components/security/IncidentQueue';
import type { PaginatedIncidents } from '../../components/security/IncidentQueue';
import Panel from '../../components/ui/Panel';
import StatTile from '../../components/ui/StatTile';

interface SecurityIncidentsProps {
    filter: string;
    filters: string[];
    counts: { open: number; acknowledged: number; high_risk: number; resolved_30_days: number };
    incidents: PaginatedIncidents;
    canTriage: boolean;
    urls: { index: string; monitoring: string };
}

/**
 * Admin → Security → Incidents: every Aegis incident, including those raised by the site assistant's guard rails.
 */
export default function SecurityIncidents({ filter, filters, counts, incidents, canTriage, urls }: SecurityIncidentsProps) {
    return (
        <AdminLayout title="Incidents">
            <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile label="Open" value={counts.open} />
                <StatTile label="Acknowledged" value={counts.acknowledged} />
                <StatTile label="High or critical, not resolved" value={counts.high_risk} />
                <StatTile label="Resolved (30 days)" value={counts.resolved_30_days} />
            </dl>

            <Panel title="Incidents" description="Risky events grouped per account (or per IP address when nobody was signed in). Open one to see its events, restore a suspended account, or resolve it.">
                <IncidentQueue incidents={incidents} filter={filter} filters={filters} filterUrl={urls.index} canTriage={canTriage} />
            </Panel>

            <p className="text-sm text-slate-400">
                For live numbers and recent risky events, see <Link href={urls.monitoring} className="text-emerald-300 hover:text-emerald-200">Threat monitoring</Link>.
            </p>
        </AdminLayout>
    );
}
