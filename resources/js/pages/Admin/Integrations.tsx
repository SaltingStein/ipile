import { Link } from '@inertiajs/react';
import AdminLayout from '../../components/AdminLayout';

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

interface IntegrationsProps {
    groups: IntegrationGroup[];
}

export default function Integrations({ groups }: IntegrationsProps) {
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
