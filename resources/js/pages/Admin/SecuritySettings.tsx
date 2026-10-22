import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import AdminLayout from '../../components/AdminLayout';
import Button from '../../components/ui/Button';
import Checkbox from '../../components/ui/Checkbox';
import Panel from '../../components/ui/Panel';
import TextField from '../../components/ui/TextField';

interface Option {
    value: string;
    label: string;
    description: string;
}

interface Category {
    key: string;
    label: string;
    description: string;
    event_count: number;
}

interface Settings {
    mode: string;
    thresholds: Record<string, number | null>;
    incident_threshold: number;
    disabled_categories: string[];
    retention_days: number;
    ai_enabled: boolean;
    ai_threshold: number;
}

interface SecuritySettingsProps {
    settings: Settings;
    modes: Option[];
    actions: Option[];
    categories: Category[];
    aiAvailable: boolean;
    urls: { update: string; index: string; guide: string; integration: string };
}

export default function SecuritySettings({ settings, modes, actions, categories, aiAvailable, urls }: SecuritySettingsProps) {
    const { data, setData, put, processing, errors } = useForm<Settings>(settings);
    const fieldErrors = errors as Record<string, string>;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        put(urls.update, { preserveScroll: true });
    }

    function setThreshold(action: string, value: number | null) {
        setData('thresholds', { ...data.thresholds, [action]: value });
    }

    function toggleCategory(key: string, monitored: boolean) {
        setData('disabled_categories', monitored
            ? data.disabled_categories.filter((category) => category !== key)
            : [...data.disabled_categories, key]);
    }

    return (
        <AdminLayout title="Monitoring settings">
            <p className="text-sm text-slate-400">
                Aegis records security events, scores them with a Markov model and other signals, and can respond automatically.
                New to it? Read the <Link href={urls.guide} className="text-emerald-300 hover:text-emerald-200">guide</Link> first.
            </p>

            <form onSubmit={submit} className="flex flex-col gap-6">
                <Panel title="Mode" description="Switching Aegis off stops all recording and responses immediately. Events already recorded are kept until the retention period ends.">
                    <fieldset className="grid gap-3 sm:grid-cols-3">
                        <legend className="sr-only">Mode</legend>
                        {modes.map((mode) => (
                            <label key={mode.value}
                                className={`flex cursor-pointer flex-col gap-2 rounded-xl border p-4 transition has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-emerald-400 ${data.mode === mode.value ? 'border-emerald-500 bg-emerald-500/10' : 'border-slate-800 bg-slate-950/60 hover:border-slate-600'}`}>
                                <span className="flex items-center gap-2">
                                    <input type="radio" name="mode" value={mode.value} checked={data.mode === mode.value}
                                        onChange={() => setData('mode', mode.value)} className="accent-emerald-500" />
                                    <span className="font-semibold text-slate-50">{mode.label}</span>
                                </span>
                                <span className="text-sm text-slate-400">{mode.description}</span>
                            </label>
                        ))}
                    </fieldset>
                    {fieldErrors.mode && <p role="alert" className="text-sm text-rose-400">{fieldErrors.mode}</p>}
                </Panel>

                <Panel title="Responses" description="Each event gets a score from 0 to 100. A response happens when the score reaches its threshold (only in “Monitor and respond” mode). Clear a box to never take that response. Failed sign-ins, which anyone can cause, never go beyond alerting the owner.">
                    <div className="grid gap-4 sm:grid-cols-2">
                        {actions.map((action) => {
                            const value = data.thresholds[action.value];

                            return (
                                <TextField key={action.value} label={action.label} name={`threshold_${action.value}`} type="number" min={1} max={100}
                                    value={value === null || value === undefined ? '' : String(value)}
                                    onChange={(input) => setThreshold(action.value, input === '' ? null : Number(input))}
                                    hint={`${action.description} Empty = never.`}
                                    error={fieldErrors[`thresholds.${action.value}`]} />
                            );
                        })}
                    </div>
                    <TextField label="Open an incident at" name="incident_threshold" type="number" min={1} max={100}
                        value={String(data.incident_threshold)} onChange={(input) => setData('incident_threshold', Number(input))}
                        hint="Events at or above this score are grouped into an incident for an administrator to review."
                        error={fieldErrors.incident_threshold} />
                </Panel>

                <Panel title="Scope" description="Which kinds of events Aegis watches. Switching a category off ignores its events entirely: they are not recorded, scored or counted.">
                    <div className="grid gap-4 sm:grid-cols-2">
                        {categories.map((category) => (
                            <Checkbox key={category.key} name={`category_${category.key}`} label={`${category.label} (${category.event_count} ${category.event_count === 1 ? 'event' : 'events'})`}
                                description={category.description}
                                checked={!data.disabled_categories.includes(category.key)}
                                onChange={(checked) => toggleCategory(category.key, checked)} />
                        ))}
                    </div>
                    {fieldErrors.disabled_categories && <p role="alert" className="text-sm text-rose-400">{fieldErrors.disabled_categories}</p>}
                </Panel>

                <Panel title="Data retention" description="Events and resolved incidents older than this are deleted every night (php artisan security:prune). Baselines are trained on the same window.">
                    <TextField label="Keep events for (days)" name="retention_days" type="number" min={7} max={730}
                        value={String(data.retention_days)} onChange={(input) => setData('retention_days', Number(input))}
                        hint="Between 7 and 730 days. Record the period in your privacy notice (NDPA/GDPR)."
                        error={fieldErrors.retention_days} />
                </Panel>

                <Panel title="AI analyst" description="Sends a redacted summary of high-risk incidents to Claude for a plain-language explanation and a verdict. It advises administrators; it never acts on accounts.">
                    {!aiAvailable && (
                        <p className="text-sm text-amber-200">
                            The Anthropic integration is not enabled yet. Set it up under <Link href={urls.integration} className="text-emerald-300 hover:text-emerald-200">Integrations → Anthropic Claude</Link>.
                        </p>
                    )}
                    <Checkbox name="ai_enabled" label="Analyse new incidents automatically" checked={data.ai_enabled} onChange={(checked) => setData('ai_enabled', checked)}
                        description="Each incident is analysed once, when it first reaches the score below. Administrators can always request an analysis by hand." />
                    <TextField label="Analyse incidents scoring at least" name="ai_threshold" type="number" min={1} max={100}
                        value={String(data.ai_threshold)} onChange={(input) => setData('ai_threshold', Number(input))}
                        hint="Higher means fewer API calls." error={fieldErrors.ai_threshold} />
                </Panel>

                <Button disabled={processing} className="self-start">Save security settings</Button>
            </form>
        </AdminLayout>
    );
}
