import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '../../components/AdminLayout';
import Panel from '../../components/ui/Panel';
import Checkbox from '../../components/ui/Checkbox';
import TextField from '../../components/ui/TextField';
import Button from '../../components/ui/Button';

interface MarketingProps {
    enabled: boolean;
    repositoryUrl: string;
    updateUrl: string;
    homeUrl: string;
    marketingUrl: string;
}

export default function Marketing({ enabled, repositoryUrl, updateUrl, homeUrl, marketingUrl }: MarketingProps) {
    const form = useForm({ enabled, repository_url: repositoryUrl });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.put(updateUrl, { preserveScroll: true });
    }

    return (
        <AdminLayout title="Marketing page">
            <Panel title="Community landing page" description="A free Ìpìlẹ̀ showcase from SaltinGStein Ltd. Changes apply across the whole site after saving.">
                <form onSubmit={submit} className="flex flex-col gap-6">
                    <Checkbox name="marketing-enabled" label="Enable marketing page" checked={form.data.enabled}
                        onChange={(value) => form.setData('enabled', value)}
                        description="Show the marketing page at the home address and /marketing. Switch it off to restore the current campaign or theme homepage and hide /marketing. This takes priority over a campaign homepage while enabled." />
                    {form.errors.enabled && <p role="alert" className="text-sm text-rose-400">{form.errors.enabled}</p>}
                    <TextField name="repository_url" label="GitHub repository URL" type="url" required
                        value={form.data.repository_url} onChange={(value) => form.setData('repository_url', value)}
                        error={form.errors.repository_url} hint="The public GitHub repository opened by every Get Ìpìlẹ̀ button. Use https://github.com/owner/repository." />
                    <div className="flex flex-wrap items-center gap-4">
                        <Button disabled={form.processing}>Save marketing settings</Button>
                        <a href={homeUrl} target="_blank" rel="noreferrer" className="text-sm text-emerald-400 underline-offset-4 hover:underline">View home page</a>
                        {enabled && <a href={marketingUrl} target="_blank" rel="noreferrer" className="text-sm text-emerald-400 underline-offset-4 hover:underline">View marketing page</a>}
                    </div>
                </form>
            </Panel>
        </AdminLayout>
    );
}
