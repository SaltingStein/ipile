import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AdminLayout from '../../components/AdminLayout';
import Button from '../../components/ui/Button';

interface IntegrationProvider {
    provider: string;
    label: string;
    description: string;
    is_enabled: boolean;
}

interface IntegrationGroup {
    type: string;
    label: string;
    providers: IntegrationProvider[];
}

interface MailDelivery {
    preferred: string | null;
    active: string | null;
    providers: { provider: string; label: string; is_enabled: boolean }[];
}

interface IntegrationsProps {
    groups: IntegrationGroup[];
    mailDelivery: MailDelivery;
}

export default function Integrations({ groups, mailDelivery }: IntegrationsProps) {
    return (
        <AdminLayout title="Integrations">
            <p className="text-sm text-slate-400">
                Choose a service to open its configuration page.
            </p>

            <div className="space-y-6">
                {groups.map((group) => {
                    const configuredProviders = group.providers.filter((provider) => provider.is_enabled).length;
                    const notConfiguredProviders = group.providers.length - configuredProviders;

                    return (
                        <section key={group.type} className="space-y-3">
                            <div className="flex items-center justify-between rounded-xl border border-slate-800/90 bg-slate-900/50 px-4 py-3">
                                <h2 className="text-lg font-semibold text-slate-200">{group.label}</h2>
                                <span className="rounded-full border border-slate-700 bg-slate-900 px-2.5 py-1 text-xs text-slate-300">
                                    {configuredProviders} configured • {notConfiguredProviders} not configured
                                </span>
                            </div>

                            {group.type === 'mail' && mailDelivery.providers.length > 1 && <MailDeliveryChoice mailDelivery={mailDelivery} />}

                            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                {group.providers.map((provider) => (
                                    <Link
                                        key={provider.provider}
                                        href={`/admin/integrations/${provider.provider}`}
                                        className="rounded-xl border border-slate-800 bg-slate-900/60 p-4 transition hover:border-emerald-500/50 hover:bg-slate-900"
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <h3 className="font-semibold text-slate-100">{provider.label}</h3>
                                            <span
                                                className={`rounded-full border px-2.5 py-1 text-xs ${
                                                    provider.is_enabled
                                                        ? 'border-emerald-700/60 bg-emerald-500/10 text-emerald-300'
                                                        : 'border-slate-700 bg-slate-900 text-slate-300'
                                                }`}
                                            >
                                                {provider.is_enabled ? 'Enabled' : 'Disabled'}
                                            </span>
                                        </div>
                                        <p className="mt-2 text-sm text-slate-400">{provider.description}</p>
                                        <p className="mt-4 text-xs font-medium text-emerald-400">Open settings →</p>
                                    </Link>
                                ))}
                            </div>
                        </section>
                    );
                })}
            </div>
        </AdminLayout>
    );
}

/**
 * Which switched-on email provider sends the site's email. "Automatic" keeps the first one switched on, in list order.
 */
function MailDeliveryChoice({ mailDelivery }: { mailDelivery: MailDelivery }) {
    const { data, setData, put, processing, errors } = useForm<{ provider: string | null }>({ provider: mailDelivery.preferred });
    const activeLabel = mailDelivery.providers.find((provider) => provider.provider === mailDelivery.active)?.label;
    const preferredIsOff = mailDelivery.preferred !== null && mailDelivery.preferred !== mailDelivery.active;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        put('/admin/integrations/mail/preferred', { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-3 rounded-xl border border-slate-800 bg-slate-900/60 p-4 md:flex-row md:items-end md:justify-between">
            <div className="flex flex-col gap-2">
                <label htmlFor="mail-provider" className="text-sm font-semibold text-slate-200">Send email through</label>
                <select
                    id="mail-provider"
                    value={data.provider ?? ''}
                    onChange={(event) => setData('provider', event.target.value === '' ? null : event.target.value)}
                    className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100"
                >
                    <option value="">Automatic (the first one switched on)</option>
                    {mailDelivery.providers.map((provider) => (
                        <option key={provider.provider} value={provider.provider}>
                            {provider.label}{provider.is_enabled ? '' : ' (switched off)'}
                        </option>
                    ))}
                </select>
                <p className="text-xs text-slate-400">
                    {activeLabel ? `Email is sent through ${activeLabel} now.` : 'No email provider is switched on, so email uses the server’s fallback mailer.'}
                    {preferredIsOff && ' Your choice is switched off, so another provider is used until you switch it on.'}
                </p>
                {errors.provider && <p role="alert" className="text-sm text-rose-400">{errors.provider}</p>}
            </div>
            <Button disabled={processing}>Save</Button>
        </form>
    );
}
