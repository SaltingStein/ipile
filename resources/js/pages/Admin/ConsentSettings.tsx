import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AdminLayout from '../../components/AdminLayout';
import Panel from '../../components/ui/Panel';
import Checkbox from '../../components/ui/Checkbox';
import TextField from '../../components/ui/TextField';
import Button from '../../components/ui/Button';
import type { ConsentConfig } from '../../types';

export default function ConsentSettings({ settings, canConfigure }: { settings: ConsentConfig; canConfigure: boolean }) {
    const form = useForm(settings);
    const errors = form.errors as Record<string, string>;
    const save = (event: FormEvent) => { event.preventDefault(); form.put('/admin/consent', { preserveScroll: true }); };

    return <AdminLayout title="Consent notices">
        <form onSubmit={save} className="space-y-6">
            <Panel title="Display and categories" description={`Notice version ${settings.version}. Saving asks visitors to choose again.`}>
                {canConfigure ? <>
                    <Checkbox name="consent-enabled" label="Show consent notices to visitors" checked={form.data.enabled} onChange={(value) => form.setData('enabled', value)} />
                    <Checkbox name="analytics-enabled" label="Offer analytics consent" checked={form.data.analytics_enabled} onChange={(value) => form.setData('analytics_enabled', value)} />
                    <Checkbox name="marketing-enabled" label="Offer marketing consent" checked={form.data.marketing_enabled} onChange={(value) => form.setData('marketing_enabled', value)} />
                    <TextField name="consent-expiry" type="number" min={1} max={365} label="Remember choices for (days)" value={String(form.data.expiry_days)} onChange={(value) => form.setData('expiry_days', Number(value))} error={errors.expiry_days} />
                </> : <p className="text-sm text-slate-400">Site administrators manage display rules and categories. You can edit notice content below.</p>}
            </Panel>
            {form.data.notices.map((notice, index) => <Panel key={notice.id} title={notice.id.toUpperCase()}>
                {canConfigure && <Checkbox name={`notice-${notice.id}`} label="Show this notice" checked={notice.enabled}
                    onChange={(value) => form.setData('notices', form.data.notices.map((item, position) => position === index ? { ...item, enabled: value } : item))} />}
                <TextField name={`notice-title-${notice.id}`} label="Heading" value={notice.title} required maxLength={120}
                    onChange={(value) => form.setData('notices', form.data.notices.map((item, position) => position === index ? { ...item, title: value } : item))}
                    error={errors[`notices.${index}.title`]} />
                <label className="space-y-2"><span className="block text-sm">Notice text</span>
                    <textarea value={notice.body} required maxLength={10000} rows={5}
                        onChange={(event) => form.setData('notices', form.data.notices.map((item, position) => position === index ? { ...item, body: event.target.value } : item))}
                        className="w-full rounded-lg border border-slate-700 bg-slate-950 p-3 text-sm" />
                </label>
                {errors[`notices.${index}.body`] && <p role="alert" className="text-sm text-rose-400">{errors[`notices.${index}.body`]}</p>}
                <details><summary className="cursor-pointer text-sm text-emerald-400">Preview notice</summary><h3 className="mt-3 font-semibold">{notice.title}</h3><p className="mt-2 whitespace-pre-wrap text-sm text-slate-300">{notice.body}</p></details>
            </Panel>)}
            <Panel title="Button labels">
                {(['accept_label', 'reject_label', 'save_label'] as const).map((key) => <TextField key={key} name={key} label={key.replace('_', ' ')} required maxLength={60}
                    value={form.data[key]} onChange={(value) => form.setData(key, value)} error={errors[key]} />)}
                {Object.entries(errors).filter(([key]) => !['accept_label', 'reject_label', 'save_label', 'expiry_days'].includes(key) && !key.endsWith('.title') && !key.endsWith('.body')).map(([key, error]) => <p key={key} role="alert" className="text-sm text-rose-400">{error}</p>)}
                <Button disabled={form.processing}>{form.processing ? 'Saving…' : 'Save consent notices'}</Button>
            </Panel>
        </form>
    </AdminLayout>;
}
