import type { FormEvent, ReactNode } from 'react';
import { useEffect } from 'react';
import { Link, router, useForm, usePage, usePoll } from '@inertiajs/react';
import AdminLayout from '../../components/AdminLayout';
import Button from '../../components/ui/Button';
import Panel from '../../components/ui/Panel';
import StatusMessage from '../../components/ui/StatusMessage';
import RiskBadge from '../../components/RiskBadge';
import type { SecurityEventRow, SecurityIncidentSummary } from './SecurityCenter';
import { verdictLabels } from './SecurityCenter';

interface Resolution {
    value: string;
    label: string;
    description: string;
}

interface SecurityIncidentProps {
    incident: SecurityIncidentSummary;
    events: SecurityEventRow[];
    resolutions: Resolution[];
    ai: { available: boolean; enabled: boolean };
    urls: { index: string; update: string; analyze: string; integration: string; restore: string };
    canRestore: boolean;
}

export default function SecurityIncident({ incident, events, resolutions, ai, urls, canRestore }: SecurityIncidentProps) {
    const isPending = incident.ai.status === 'pending';
    const { start, stop } = usePoll(4000, { only: ['incident'] }, { autoStart: false });

    // While the AI analysis is queued, refresh the incident until it lands.
    useEffect(() => {
        if (isPending) {
            start();
        } else {
            stop();
        }

        return stop;
    }, [isPending]);

    const subject = incident.user ? `${incident.user.name}${incident.user.email ? ` (${incident.user.email})` : ''}` : `Signed-out visitor at ${incident.ip_address ?? 'unknown IP'}`;

    return (
        <AdminLayout title={`Incident #${incident.id}`}>
            <Link href={urls.index} className="text-sm text-emerald-300 hover:text-emerald-200">← All incidents</Link>

            <Panel title={subject} description={`${incident.event_count} ${incident.event_count === 1 ? 'event' : 'events'} from ${new Date(incident.first_event_at).toLocaleString()} to ${new Date(incident.last_event_at).toLocaleString()}.`}>
                <dl className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <Fact label="Highest risk"><RiskBadge score={incident.risk_score} /></Fact>
                    <Fact label="Policy recommends">{incident.recommended_action?.label ?? 'No action'}</Fact>
                    <Fact label="Taken automatically">{incident.action_taken?.label ?? 'Nothing (monitor mode, or below thresholds)'}</Fact>
                    <Fact label="Status">
                        <span className="capitalize">{incident.status}</span>
                        {incident.resolution && <span className="text-slate-400"> · {resolutions.find((option) => option.value === incident.resolution)?.label ?? incident.resolution}</span>}
                    </Fact>
                </dl>
                {incident.user?.status === 'suspended' && (
                    <div className="flex flex-col gap-3">
                        <StatusMessage tone="error" message={canRestore
                            ? 'This account is suspended. Restore it once you are satisfied it is safe; the person can then sign in again.'
                            : 'This account is suspended. Someone with the suspend_users permission can restore it here.'} />
                        {canRestore && (
                            <Button type="button" variant="secondary" className="self-start"
                                onClick={() => window.confirm('Restore this account? The person can sign in again straight away.') && router.post(urls.restore, {}, { preserveScroll: true })}>
                                Restore account
                            </Button>
                        )}
                    </div>
                )}
                {incident.resolved_at && (
                    <p className="text-sm text-slate-400">
                        Resolved {new Date(incident.resolved_at).toLocaleString()}{incident.resolved_by ? ` by ${incident.resolved_by}` : ''}.{incident.resolution_note ? ` “${incident.resolution_note}”` : ''}
                    </p>
                )}
            </Panel>

            <AnalysisPanel incident={incident} ai={ai} urls={urls} />

            <Panel title="Timeline" description="Each event's own score comes from its signals; the posture score is the account's running Markov risk level. The higher of the two drives the response.">
                <ol className="flex flex-col gap-3">
                    {events.map((event) => (
                        <li key={event.id} className="rounded-xl border border-slate-800 bg-slate-950/60 p-4 text-sm">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="font-medium text-slate-100">{event.label} <span className="font-normal text-slate-500">({event.type})</span></span>
                                <span className="flex items-center gap-2 text-xs text-slate-400">
                                    Event <RiskBadge score={event.risk_score} /> Posture <RiskBadge score={event.posture_score} />
                                </span>
                            </div>
                            <p className="mt-1 text-slate-400">
                                {new Date(event.occurred_at).toLocaleString()} · {event.device ?? 'Unknown device'} · {event.ip_address ?? 'Unknown IP'}
                                {event.action_taken && <span className="text-slate-200"> · Response: {event.action_taken.label}</span>}
                                {!event.action_taken && event.recommended_action && <span> · Would: {event.recommended_action.label}</span>}
                            </p>
                            {event.signals.length > 0 && (
                                <ul className="mt-3 flex flex-col gap-1.5">
                                    {event.signals.map((signal) => (
                                        <li key={signal.key} className="flex flex-wrap items-center gap-2 text-slate-300">
                                            <span className="w-40 shrink-0 text-slate-200">{signal.label}</span>
                                            <span className="h-1.5 w-24 overflow-hidden rounded-full bg-slate-800" aria-hidden="true">
                                                <span className="block h-full rounded-full bg-slate-300" style={{ width: `${Math.round(signal.score * 100)}%` }} />
                                            </span>
                                            <span className="tabular-nums text-slate-400">{Math.round(signal.score * 100)}% × weight {signal.weight}</span>
                                            {signal.detail && <span className="text-slate-400">· {signal.detail}</span>}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {Object.keys(event.context).length > 0 && (
                                <p className="mt-2 font-mono text-xs text-slate-500">
                                    {Object.entries(event.context).map(([key, value]) => `${key}=${String(value)}`).join('  ')}
                                </p>
                            )}
                        </li>
                    ))}
                </ol>
            </Panel>

            {incident.status !== 'resolved' && <TriagePanel incident={incident} resolutions={resolutions} updateUrl={urls.update} />}
        </AdminLayout>
    );
}

function AnalysisPanel({ incident, ai, urls }: Pick<SecurityIncidentProps, 'incident' | 'ai' | 'urls'>) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const analysis = incident.ai.analysis;

    return (
        <Panel title="AI analyst" description="A second opinion from Claude on a redacted summary of this incident (no names, emails or IP addresses). Advisory only: it never changes the account.">
            {incident.ai.status === 'pending' && <p className="text-sm text-slate-300" role="status">Analysis in progress… this page refreshes when it is ready.</p>}
            {incident.ai.error && <StatusMessage tone="error" message={incident.ai.error} />}
            {errors.analysis && <StatusMessage tone="error" message={errors.analysis} />}

            {analysis && (
                <div className="flex flex-col gap-3 text-sm">
                    <p className="text-base text-slate-100">
                        <span className="font-semibold">{verdictLabels[analysis.verdict] ?? analysis.verdict}</span>
                        <span className="text-slate-400"> · {Math.round(analysis.confidence * 100)}% confident · suggests: {analysis.recommended_action.replace('_', ' ')}</span>
                    </p>
                    <p className="text-slate-200">{analysis.summary}</p>
                    <p className="whitespace-pre-line text-slate-300">{analysis.explanation}</p>
                    {analysis.suggested_steps.length > 0 && (
                        <ul className="list-disc space-y-1 pl-5 text-slate-300">
                            {analysis.suggested_steps.map((step, index) => <li key={index}>{step}</li>)}
                        </ul>
                    )}
                    <p className="text-xs text-slate-500">{analysis.model}{incident.ai.analyzed_at ? ` · ${new Date(incident.ai.analyzed_at).toLocaleString()}` : ''}</p>
                </div>
            )}

            {ai.available ? (
                <Button type="button" variant="secondary" className="self-start" disabled={incident.ai.status === 'pending'}
                    onClick={() => router.post(urls.analyze, {}, { preserveScroll: true })}>
                    {analysis ? 'Analyse again' : 'Analyse with AI'}
                </Button>
            ) : (
                <p className="text-sm text-slate-400">
                    Not configured. Add an Anthropic API key under <Link href={urls.integration} className="text-emerald-300 hover:text-emerald-200">Integrations → Anthropic Claude</Link>
                    {!ai.enabled && ', then turn on AI analysis in Monitoring settings to analyse new incidents automatically'}.
                </p>
            )}
        </Panel>
    );
}

function TriagePanel({ incident, resolutions, updateUrl }: { incident: SecurityIncidentSummary; resolutions: Resolution[]; updateUrl: string }) {
    const { data, setData, put, processing, errors } = useForm({ status: 'resolved', resolution: '', note: '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        put(updateUrl, { preserveScroll: true });
    }

    return (
        <Panel title="Triage" description="Resolving resets the account's risk posture so later events are judged afresh.">
            {incident.status === 'open' && (
                <Button type="button" variant="secondary" className="self-start"
                    onClick={() => router.put(updateUrl, { status: 'acknowledged' }, { preserveScroll: true })}>
                    Acknowledge (I am looking into it)
                </Button>
            )}
            <form onSubmit={submit} className="flex flex-col gap-4">
                <fieldset className="grid gap-3 sm:grid-cols-3">
                    <legend className="mb-2 text-sm font-medium text-slate-200">Outcome</legend>
                    {resolutions.map((option) => (
                        <label key={option.value}
                            className={`flex cursor-pointer flex-col gap-1 rounded-xl border p-4 text-sm transition has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-emerald-400 ${data.resolution === option.value ? 'border-emerald-500 bg-emerald-500/10' : 'border-slate-800 bg-slate-950/60 hover:border-slate-600'}`}>
                            <span className="flex items-center gap-2">
                                <input type="radio" name="resolution" value={option.value} checked={data.resolution === option.value}
                                    onChange={() => setData('resolution', option.value)} className="accent-emerald-500" />
                                <span className="font-semibold text-slate-50">{option.label}</span>
                            </span>
                            <span className="text-slate-400">{option.description}</span>
                        </label>
                    ))}
                </fieldset>
                {errors.resolution && <p role="alert" className="text-sm text-rose-400">{errors.resolution}</p>}
                <div className="flex flex-col gap-1.5">
                    <label htmlFor="note" className="text-sm font-medium text-slate-200">Note (optional)</label>
                    <textarea id="note" value={data.note} onChange={(event) => setData('note', event.target.value)} maxLength={1000} rows={3}
                        className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-emerald-500 focus:outline-none" />
                    {errors.note && <p className="text-sm text-rose-400">{errors.note}</p>}
                </div>
                <Button disabled={processing || data.resolution === ''} className="self-start">Resolve incident</Button>
            </form>
        </Panel>
    );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-1">
            <dt className="text-xs uppercase tracking-wide text-slate-400">{label}</dt>
            <dd className="text-slate-100">{children}</dd>
        </div>
    );
}
