import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '../../components/AdminLayout';
import Button from '../../components/ui/Button';
import Panel from '../../components/ui/Panel';

interface VisibilityOption {
    value: string;
    label: string;
    description: string;
}

interface ApiDocsProps {
    visibility: string;
    options: VisibilityOption[];
    portalUrl: string;
    docsUrl: string;
    specUrl: string;
    updateUrl: string;
}

export default function ApiDocs({ visibility, options, portalUrl, docsUrl, specUrl, updateUrl }: ApiDocsProps) {
    const { data, setData, put, processing, errors } = useForm({ visibility });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        put(updateUrl, { preserveScroll: true });
    }

    return (
        <AdminLayout title="API documentation">
            <Panel title="Who can see the API docs"
                description="The docs are generated from the /api/v1 routes, validation rules and resources, so new endpoints appear automatically. Use “Try it” with a Sanctum token from POST /api/v1/auth/login.">
                <form onSubmit={submit} className="flex flex-col gap-5">
                    <fieldset className="grid gap-3 sm:grid-cols-2">
                        <legend className="sr-only">Visibility</legend>
                        {options.map((option) => (
                            <label key={option.value}
                                className={`flex cursor-pointer flex-col gap-2 rounded-xl border p-4 transition has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-emerald-400 ${data.visibility === option.value ? 'border-emerald-500 bg-emerald-500/10' : 'border-slate-800 bg-slate-950/60 hover:border-slate-600'}`}>
                                <span className="flex items-center gap-2">
                                    <input type="radio" name="visibility" value={option.value} checked={data.visibility === option.value}
                                        onChange={() => setData('visibility', option.value)} className="accent-emerald-500" />
                                    <span className="font-semibold text-slate-50">{option.label}</span>
                                </span>
                                <span className="text-sm text-slate-400">{option.description}</span>
                            </label>
                        ))}
                    </fieldset>
                    {errors.visibility && <p role="alert" className="text-sm text-rose-400">{errors.visibility}</p>}
                    <div className="flex flex-wrap items-center gap-4">
                        <Button disabled={processing}>Save</Button>
                        {visibility !== 'disabled' && <>
                            <a href={portalUrl} target="_blank" rel="noreferrer" className="text-sm text-emerald-400 hover:text-emerald-300">Developer portal</a>
                            <a href={docsUrl} target="_blank" rel="noreferrer" className="text-sm text-emerald-400 hover:text-emerald-300">Open API docs</a>
                            <a href={specUrl} target="_blank" rel="noreferrer" className="text-sm text-emerald-400 hover:text-emerald-300">OpenAPI JSON</a>
                        </>}
                    </div>
                </form>
            </Panel>
        </AdminLayout>
    );
}
