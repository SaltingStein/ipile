import { Link } from '@inertiajs/react';
import AdminLayout from '../../components/AdminLayout';
import Panel from '../../components/ui/Panel';
import { outcomeTone } from './AssistantActivity';
import type { InteractionSummary } from './AssistantActivity';

interface Interaction extends InteractionSummary {
    conversation_id: string | null;
    team_id: number | null;
    team_role: string | null;
    capabilities: { modes?: string[]; tools?: string[]; insights?: string[] };
    tools: { tool: string; arguments: Record<string, string>; result_bytes: number }[];
    input_tokens: number | null;
    output_tokens: number | null;
    question: string | null;
    answer_html: string | null;
}

/**
 * One site assistant request from the activity log: who asked, what they were allowed at the time, the exchange and
 * the tools used (arguments only, never the data they returned).
 */
export default function AssistantInteraction({ interaction }: { interaction: Interaction }) {
    const details: [string, string][] = [
        ['When', interaction.created_at ? new Date(interaction.created_at).toLocaleString() : '—'],
        ['Person', interaction.person ? `${interaction.person.name}${interaction.person.email ? ` (${interaction.person.email})` : ''}` : 'Deleted account'],
        ['Roles at the time', interaction.roles.join(', ') || 'None'],
        ['Current team', interaction.team_id ? `#${interaction.team_id}${interaction.team_role ? `, ${interaction.team_role}` : ''}` : 'None'],
        ['Where', interaction.channel],
        ['Mode', interaction.mode ?? '—'],
        ['Modes allowed', (interaction.capabilities.modes ?? []).join(', ') || '—'],
        ['Tools offered', (interaction.capabilities.tools ?? []).join(', ') || '—'],
        ['Insights offered', (interaction.capabilities.insights ?? []).join(', ') || '—'],
        ['AI model', [interaction.provider, interaction.model].filter(Boolean).join(' · ') || '—'],
        ['Tokens (in / out)', interaction.input_tokens === null ? '—' : `${interaction.input_tokens} / ${interaction.output_tokens ?? 0}`],
        ['Time taken', interaction.duration_ms === null ? '—' : `${(interaction.duration_ms / 1000).toFixed(1)} s`],
    ];

    return (
        <AdminLayout title="Assistant request">
            <Link href="/admin/assistant/activity" className="text-sm text-emerald-300 hover:text-emerald-200">← All activity</Link>

            <Panel title={interaction.outcome_label} description="A snapshot taken when the request was made; later role changes do not alter it.">
                <p className={`text-sm font-medium ${outcomeTone[interaction.outcome] ?? 'text-slate-300'}`}>Outcome: {interaction.outcome_label}</p>
                <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]">
                    {details.map(([label, value]) => (
                        <div key={label} className="contents">
                            <dt className="text-slate-400">{label}</dt>
                            <dd className="text-slate-100">{value}</dd>
                        </div>
                    ))}
                </dl>
            </Panel>

            <Panel title="Exchange" description="Refused requests are not stored here; their incident in Admin → Security → Incidents shows what was asked.">
                {interaction.question === null && interaction.answer_html === null && <p className="text-sm text-slate-400">No message was stored for this request.</p>}
                {interaction.question !== null && (
                    <div className="rounded-xl bg-emerald-500/10 px-4 py-3 text-sm whitespace-pre-line text-emerald-50"><span className="sr-only">Question: </span>{interaction.question}</div>
                )}
                {interaction.answer_html !== null && (
                    <div className="assistant-reply rounded-xl bg-slate-800 px-4 py-3 text-sm text-slate-100" dangerouslySetInnerHTML={{ __html: interaction.answer_html }} />
                )}
            </Panel>

            <Panel title={`Tools used (${interaction.tools.length})`} description="Arguments are shortened and masked; the data the tools returned is never stored.">
                {interaction.tools.length === 0 ? <p className="text-sm text-slate-400">None.</p> : (
                    <ul className="flex flex-col gap-2 text-sm">
                        {interaction.tools.map((call, index) => (
                            <li key={index} className="rounded-lg border border-slate-800 px-3 py-2">
                                <span className="font-medium text-slate-100">{call.tool}</span>
                                <span className="text-slate-400"> · {call.result_bytes} bytes returned</span>
                                {Object.keys(call.arguments).length > 0 && (
                                    <span className="block text-xs text-slate-300">{Object.entries(call.arguments).map(([name, value]) => `${name}: ${value}`).join(' · ')}</span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </AdminLayout>
    );
}
