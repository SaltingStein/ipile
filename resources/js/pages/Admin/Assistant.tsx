import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import AdminLayout from '../../components/AdminLayout';
import Button from '../../components/ui/Button';
import Checkbox from '../../components/ui/Checkbox';
import Panel from '../../components/ui/Panel';
import StatusMessage from '../../components/ui/StatusMessage';
import TextField from '../../components/ui/TextField';

interface AssistantSettingsProps {
    settings: { source: 'built_in' | 'third_party' | 'off'; enabled: boolean; provider: string | null; metrics: string[]; daily_messages: number; lockout: 'suspend' | 'sign_out'; staff_modes: string[] };
    widgetSettingsUrl: string | null;
    providers: { value: string; label: string }[];
    metrics: { key: string; label: string; description: string; permission: string }[];
    blockedTables: string[];
    protectedColumns: Record<string, string[]>;
    maxDailyMessages: number;
    providerUnavailable: boolean;
    staffModes: { value: string; label: string; permission: string }[];
}

const staffModeDescriptions: Record<string, string> = {
    core_guidance: 'Answers questions about running and extending the site (admin settings, security monitoring, the developer guide) from the internal guides as well as the Help Center.',
    analytics: 'Summarises and compares the business figures ticked above that the person may see, with tables and changes over time.',
};

export default function AssistantSettings({ settings, widgetSettingsUrl, providers, metrics, blockedTables, protectedColumns, maxDailyMessages, providerUnavailable, staffModes }: AssistantSettingsProps) {
    const form = useForm({
        source: settings.source,
        enabled: settings.enabled,
        // A model that has been switched off is not in the list, so it shows as not chosen.
        provider: providerUnavailable ? '' : (settings.provider ?? ''),
        metrics: settings.metrics,
        daily_messages: String(settings.daily_messages),
        lockout: settings.lockout,
        staff_modes: settings.staff_modes,
    });

    function save(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({ ...data, provider: data.provider || null, daily_messages: Number(data.daily_messages) }));
        form.put('/admin/assistant', { preserveScroll: true });
    }

    function toggleMetric(key: string, checked: boolean) {
        form.setData('metrics', checked ? [...form.data.metrics, key] : form.data.metrics.filter((selected) => selected !== key));
    }

    function toggleStaffMode(mode: string, checked: boolean) {
        form.setData('staff_modes', checked ? [...form.data.staff_modes, mode] : form.data.staff_modes.filter((selected) => selected !== mode));
    }

    return (
        <AdminLayout title="Site assistant">
            <form onSubmit={save} className="flex flex-col gap-6">
                <Panel title="Assistant source" description="Choose what powers the assistant on signed-in workspace pages. Guest accounts do not get access.">
                    <label htmlFor="assistant-source" className="text-sm font-medium text-slate-200">Assistant source</label>
                    <select id="assistant-source" value={form.data.source}
                        onChange={(event) => form.setData('source', event.target.value as typeof settings.source)}
                        className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100">
                        <option value="built_in">Built-in assistant</option>
                        <option value="third_party">Third-party widget</option>
                        <option value="off">Off</option>
                    </select>
                    {form.errors.source && <p className="text-sm text-rose-400">{form.errors.source}</p>}
                    {form.data.source === 'third_party' && <>
                        <p className="text-sm text-amber-200">The third-party service handles its own access, data and conversations. The built-in assistant’s guard rails, business figures, message limits and account lock-out rules below do not cover it.</p>
                        {widgetSettingsUrl
                            ? <Link href={widgetSettingsUrl} className="text-sm text-emerald-400 underline">Configure third-party widget in Integrations</Link>
                            : <p className="text-sm text-slate-400">Ask someone who manages integrations to configure and enable the third-party widget.</p>}
                    </>}
                    {form.data.source === 'off' && <p className="text-sm text-slate-400">Neither assistant is offered. Your saved built-in settings are kept below.</p>}
                </Panel>
                <fieldset disabled={form.data.source !== 'built_in'} className="flex min-w-0 flex-col gap-6 disabled:opacity-60">
                <Panel title="Assistant" description="An AI help assistant in the sidebar for everyone with a full account. It answers from the Help Center, the person’s own account and teams, and the figures you allow below.">
                    {providerUnavailable && (
                        <StatusMessage tone="error" message="The AI model chosen for the assistant has been switched off in Admin → Integrations, so the assistant cannot answer. Choose another model below and save." />
                    )}
                    <Checkbox label="Assistant is on" name="assistant-enabled" checked={form.data.enabled}
                        onChange={(enabled) => form.setData('enabled', enabled)} />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="flex flex-col gap-1.5">
                            <label htmlFor="assistant-provider" className="text-sm font-medium text-slate-200">AI model</label>
                            <select id="assistant-provider" value={form.data.provider} onChange={(event) => form.setData('provider', event.target.value)}
                                aria-invalid={form.errors.provider ? true : undefined}
                                className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 aria-invalid:border-rose-500">
                                <option value="">Choose…</option>
                                {providers.map((provider) => <option key={provider.value} value={provider.value}>{provider.label}</option>)}
                            </select>
                            {form.errors.provider
                                ? <p className="text-sm text-rose-400">{form.errors.provider}</p>
                                : <p className="text-xs text-slate-400">{providers.length === 0 ? 'Switch on an AI model in Admin → Integrations → AI models first.' : 'It also screens every message before the assistant sees it.'}</p>}
                        </div>
                        <TextField label="Messages per person per day" name="assistant-daily" type="number" min={1} max={maxDailyMessages} value={form.data.daily_messages}
                            onChange={(value) => form.setData('daily_messages', value)} error={form.errors.daily_messages} />
                    </div>
                </Panel>

                <Panel title="Business figures it may read" description="Counts and totals only. A person sees a figure only if you tick it here and they also have its permission, so nobody learns more than their admin pages show them.">
                    <div className="flex flex-col gap-3">
                        {metrics.map((metric) => (
                            <Checkbox key={metric.key} label={metric.label} name={`metric-${metric.key}`} checked={form.data.metrics.includes(metric.key)}
                                description={`${metric.description} Needs the ${metric.permission} permission.`}
                                onChange={(checked) => toggleMetric(metric.key, checked)} />
                        ))}
                    </div>
                </Panel>

                <Panel title="Staff modes" description="Extra modes of the same assistant for people who run the site. Each message is routed automatically; everyone else, and anyone without the permission shown, gets the normal help assistant. Screening, the message limit and the lock-out rules below apply in every mode. Grant the permissions in Admin → Roles & permissions.">
                    <div className="flex flex-col gap-3">
                        {staffModes.map((mode) => (
                            <Checkbox key={mode.value} label={mode.label} name={`staff-mode-${mode.value}`} checked={form.data.staff_modes.includes(mode.value)}
                                description={`${staffModeDescriptions[mode.value] ?? ''} Needs the ${mode.permission} permission.`}
                                onChange={(checked) => toggleStaffMode(mode.value, checked)} />
                        ))}
                    </div>
                    {form.errors.staff_modes && <p className="text-sm text-rose-400">{form.errors.staff_modes}</p>}
                </Panel>

                <Panel title="When someone asks for protected data" description="Applies to everyone, super admins included. The request is always refused, logged as a high-risk incident and emailed to administrators.">
                    <fieldset className="flex flex-col gap-2">
                        <legend className="sr-only">What happens to the account</legend>
                        {([
                            ['suspend', 'Suspend the account until an administrator restores it', 'They are signed out everywhere and cannot sign in until someone with the suspend_users permission chooses Restore account on the incident. Strictest.'],
                            ['sign_out', 'Sign them out and require their authenticator code', 'They are signed out everywhere and their trusted devices are forgotten, so they need a code from their authenticator app to get back in. Accounts without two-factor are still suspended.'],
                        ] as const).map(([value, label, description]) => (
                            <label key={value} htmlFor={`assistant-lockout-${value}`}
                                className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm ${form.data.lockout === value ? 'border-emerald-500 bg-emerald-500/5' : 'border-slate-800'}`}>
                                <input id={`assistant-lockout-${value}`} type="radio" name="assistant-lockout" checked={form.data.lockout === value}
                                    onChange={() => form.setData('lockout', value)} className="mt-0.5 size-4 accent-emerald-500" />
                                <span className="flex flex-col">
                                    <span className="font-medium text-slate-100">{label}</span>
                                    <span className="text-xs text-slate-400">{description}</span>
                                </span>
                            </label>
                        ))}
                    </fieldset>
                    {form.errors.lockout && <p className="text-sm text-rose-400">{form.errors.lockout}</p>}
                    {form.data.lockout === 'suspend' && (
                        <p className="text-xs text-amber-200">
                            If every super admin is suspended, nobody can restore accounts from the dashboard. Someone with server access can run <code>php artisan ipile:restore-user email@example.com</code>.
                        </p>
                    )}
                </Panel>

                </fieldset>
                <Button disabled={form.processing} className="self-start">{form.processing ? 'Saving…' : 'Save assistant'}</Button>
            </form>

            <Panel title="Built-in guard rails (always on)" description="These protect the built-in assistant and cannot be switched off or loosened here. They do not cover third-party widgets.">
                <ul className="flex list-disc flex-col gap-2 pl-5 text-sm text-slate-300">
                    <li>Every message is checked before the assistant sees it. Asking for passwords, sign-in or recovery codes, two-factor secrets, API or payment keys, other people’s details, or protected system data is refused.</li>
                    <li>Whoever asks (super admins included) is signed out everywhere at once, then suspended or asked for their authenticator code as chosen above.</li>
                    <li>Each case is logged in Threat monitoring as a high-risk incident, even while monitoring is off, and everyone who can see threat monitoring gets an email.</li>
                    <li>Answers are checked for anything that looks like a key, token or code before they are shown.</li>
                </ul>
                <details className="text-sm text-slate-300">
                    <summary className="cursor-pointer font-medium text-slate-200">Data the assistant can never read</summary>
                    <div className="mt-3 flex flex-col gap-2">
                        <p><span className="text-slate-400">Tables:</span> {blockedTables.join(', ')}</p>
                        <p><span className="text-slate-400">Columns:</span> {Object.entries(protectedColumns).map(([table, columns]) => columns.map((column) => `${table}.${column}`).join(', ')).join(', ')}</p>
                    </div>
                </details>
            </Panel>
        </AdminLayout>
    );
}
