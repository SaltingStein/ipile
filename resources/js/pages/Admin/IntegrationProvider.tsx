import type { FormEvent } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import AdminLayout from '../../components/AdminLayout';
import Button from '../../components/ui/Button';
import Checkbox from '../../components/ui/Checkbox';
import Panel from '../../components/ui/Panel';
import StatusMessage from '../../components/ui/StatusMessage';
import TextField from '../../components/ui/TextField';
import type { SharedPageProps } from '../../types';

interface IntegrationFieldState {
    name: string;
    label: string;
    input: 'text' | 'number' | 'email' | 'select' | 'password';
    required: boolean;
    secret: boolean;
    options: string[];
    help: string | null;
    value: string | number | null;
    has_value: boolean;
}

interface IntegrationProviderState {
    provider: string;
    label: string;
    description: string;
    is_enabled: boolean;
    can_test: boolean;
    fields: IntegrationFieldState[];
    last_test: { at: string; succeeded: boolean | null; message: string } | null;
}

interface IntegrationProviderProps {
    groupLabel: string;
    provider: IntegrationProviderState;
    indexUrl: string;
}

const keepScroll = { preserveScroll: true };

export default function IntegrationProviderPage({ groupLabel, provider, indexUrl }: IntegrationProviderProps) {
    const { errors: pageErrors } = usePage<SharedPageProps>().props;
    const initialConfig = Object.fromEntries(provider.fields.map((field) => [field.name, field.secret ? '' : String(field.value ?? '')]));
    const { data, setData, put, processing, errors } = useForm({
        is_enabled: provider.is_enabled,
        config: initialConfig as Record<string, string>,
    });

    function setField(name: string, value: string) {
        setData('config', { ...data.config, [name]: value });
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        put(`/admin/integrations/${provider.provider}`, {
            ...keepScroll,
            onSuccess: () => setData('config', Object.fromEntries(provider.fields.map((field) => [field.name, field.secret ? '' : data.config[field.name] ?? '']))),
        });
    }

    const fieldErrors = errors as Record<string, string | undefined>;

    return (
        <AdminLayout title={`${provider.label} integration`}>
            <div className="space-y-2">
                <Link href={indexUrl} className="text-sm text-emerald-400 hover:text-emerald-300">
                    ← Back to integrations
                </Link>
                <p className="text-xs uppercase tracking-[0.2em] text-slate-500">{groupLabel}</p>
                <p className="text-sm text-slate-400">{provider.description}</p>
            </div>

            <form onSubmit={submit} className="flex max-w-xl flex-col gap-4">
                <Panel title="Provider settings">
                    {provider.fields.map((field) => {
                        const error = fieldErrors[`config.${field.name}`];
                        const hint = [field.help, field.secret && field.has_value ? 'Saved. Leave blank to keep the current value.' : null]
                            .filter(Boolean)
                            .join(' ');

                        if (field.input === 'select') {
                            return (
                                <label key={field.name} htmlFor={`${provider.provider}_${field.name}`} className="flex flex-col gap-1.5 text-sm font-medium text-slate-200">
                                    {field.label}
                                    <select
                                        id={`${provider.provider}_${field.name}`}
                                        value={data.config[field.name] ?? ''}
                                        onChange={(event) => setField(field.name, event.target.value)}
                                        className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-normal text-slate-100"
                                    >
                                        <option value="">Choose…</option>
                                        {field.options.map((option) => (
                                            <option key={option} value={option}>
                                                {option}
                                            </option>
                                        ))}
                                    </select>
                                    {hint && !error && <span className="text-xs font-normal text-slate-400">{hint}</span>}
                                    {error && <span className="text-sm font-normal text-rose-400">{error}</span>}
                                </label>
                            );
                        }

                        return (
                            <TextField
                                key={field.name}
                                label={field.required ? field.label : `${field.label} (optional)`}
                                name={`${provider.provider}_${field.name}`}
                                type={field.input}
                                autoComplete={field.secret ? 'new-password' : 'off'}
                                placeholder={field.secret && field.has_value ? '••••••••' : ''}
                                hint={hint || undefined}
                                value={data.config[field.name] ?? ''}
                                onChange={(value) => setField(field.name, value)}
                                error={error}
                            />
                        );
                    })}
                    <Checkbox
                        label={`Enable ${provider.label}`}
                        name={`${provider.provider}_is_enabled`}
                        description="All required fields must be filled before it can be enabled."
                        checked={data.is_enabled}
                        onChange={(checked) => setData('is_enabled', checked)}
                    />
                    <div className="flex flex-wrap gap-3">
                        <Button disabled={processing}>Save {provider.label}</Button>
                        {provider.can_test && (
                            <Button type="button" variant="secondary" onClick={() => router.post(`/admin/integrations/${provider.provider}/test`, {}, keepScroll)}>
                                Test connection
                            </Button>
                        )}
                    </div>
                    <StatusMessage tone="error" message={pageErrors[`${provider.provider}.test`]} />
                    {provider.last_test && (
                        <p className="text-xs text-slate-400">
                            Last tested {new Date(provider.last_test.at).toLocaleString()}:{' '}
                            {provider.last_test.succeeded === true ? 'passed' : provider.last_test.succeeded === false ? 'failed' : 'not available'}.
                        </p>
                    )}
                    {!provider.can_test && <p className="text-xs text-slate-500">A connection test will be added with this provider&apos;s driver.</p>}
                </Panel>
            </form>
        </AdminLayout>
    );
}

