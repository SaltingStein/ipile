import { useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import AdminLayout from '../../components/AdminLayout';
import Panel from '../../components/ui/Panel';
import TextField from '../../components/ui/TextField';
import Button from '../../components/ui/Button';
import Checkbox from '../../components/ui/Checkbox';
import type { Branding } from '../../types';

interface BrandingProps {
    settings: Branding;
    aboutPageVisible: boolean;
    creditVisible: boolean;
    updateUrl: string;
}

export default function Branding({ settings, aboutPageVisible, creditVisible, updateUrl }: BrandingProps) {
    const form = useForm({
        name: settings.name,
        organisation_name: settings.organisation_name ?? '',
        service_name: settings.service_name ?? '',
        contact_email: settings.contact_email ?? '',
        version: settings.version,
        launched_on: settings.launched_on ?? '',
        logo: null as File | null,
        remove_logo: false,
        about_page_visible: aboutPageVisible,
        boilerplate_credit_visible: creditVisible,
    });
    const fileInput = useRef<HTMLInputElement>(null);
    const [preview, setPreview] = useState<string | null>(null);

    useEffect(() => {
        if (!form.data.logo) {
            setPreview(null);
            return;
        }
        const url = URL.createObjectURL(form.data.logo);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [form.data.logo]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(updateUrl, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.setData((data) => ({ ...data, logo: null, remove_logo: false }));
                if (fileInput.current) fileInput.current.value = '';
            },
        });
    };
    const logoUrl = form.data.remove_logo ? null : preview || settings.logo_url;

    return (
        <AdminLayout title="Branding">
            <Panel title="App identity" description="Your product's name, organisation, logo and version, shown across your application. They identify your product; the Ìpìlẹ̀ credit below is separate.">
                <form onSubmit={submit} className="space-y-6">
                    <TextField name="brand-name" label="App name" value={form.data.name} maxLength={80} required
                        onChange={(value) => form.setData('name', value)} error={form.errors.name}
                        hint="Shown in the header (when no logo is set), page titles, emails and payment pages." />
                    <TextField name="brand-service-name" label="Service name" value={form.data.service_name} maxLength={120}
                        onChange={(value) => form.setData('service_name', value)} error={form.errors.service_name}
                        hint="Optional. The service your legal pages cover, if it differs from the app name. Fills [service name] in your legal pages. Leave blank to use the app name." />
                    <TextField name="brand-organisation-name" label="Organisation name" value={form.data.organisation_name} maxLength={120}
                        onChange={(value) => form.setData('organisation_name', value)} error={form.errors.organisation_name}
                        hint="Optional. The business that runs the app, for example Acme Ltd. Used in your copyright line and fills [organisation name] in your legal pages. Leave blank to use the app name." />
                    <TextField name="brand-contact-email" label="Contact email" type="email" value={form.data.contact_email} maxLength={255}
                        onChange={(value) => form.setData('contact_email', value)} error={form.errors.contact_email}
                        hint="Optional. Public address for privacy and legal questions. Fills [contact email] and [privacy contact] in your legal pages." />
                    <TextField name="brand-version" label="App version" value={form.data.version} maxLength={32} required
                        onChange={(value) => form.setData('version', value)} error={form.errors.version} hint="For example 1.0.0 or 1.0.0-beta.1. Shown in the footer." />
                    <TextField name="brand-launched-on" label="Launch date" type="date" value={form.data.launched_on}
                        onChange={(value) => form.setData('launched_on', value)} error={form.errors.launched_on}
                        hint="Optional. Shown in the public footer and used as the first year of the copyright notice." />
                    <div className="space-y-3">
                        <label htmlFor="brand-logo" className="block text-sm font-medium text-slate-200">Logo</label>
                        <div className="flex min-h-24 items-center justify-center rounded-lg border border-slate-700 bg-slate-950 p-4">
                            {logoUrl ? <img src={logoUrl} alt="Logo preview" className="max-h-24 max-w-full object-contain" /> : <span className="text-sm text-slate-500">Your app name will appear when no logo is set.</span>}
                        </div>
                        <input ref={fileInput} id="brand-logo" type="file" accept="image/png,image/jpeg,image/webp"
                            disabled={form.processing || form.data.remove_logo}
                            onChange={(event) => form.setData('logo', event.target.files?.[0] ?? null)}
                            aria-describedby="brand-logo-help" aria-invalid={!!form.errors.logo}
                            className="block w-full text-sm text-slate-300 file:mr-4 file:rounded-lg file:border-0 file:bg-slate-800 file:px-4 file:py-2 file:text-slate-100 disabled:opacity-50" />
                        <p id="brand-logo-help" className="text-xs text-slate-400">PNG, JPG or WebP. Maximum 2 MB and 4096 × 4096 pixels.</p>
                        {form.errors.logo && <p role="alert" className="text-sm text-rose-400">{form.errors.logo}</p>}
                        {settings.logo_url && (
                            <Checkbox name="remove-logo" label="Remove the current logo" checked={form.data.remove_logo}
                                onChange={(checked) => {
                                    form.setData((data) => ({ ...data, remove_logo: checked, logo: null }));
                                    if (fileInput.current) fileInput.current.value = '';
                                }} />
                        )}
                        {form.errors.remove_logo && <p role="alert" className="text-sm text-rose-400">{form.errors.remove_logo}</p>}
                        {form.progress && <p role="status" className="text-xs text-slate-400">Uploading: {form.progress.percentage}%</p>}
                    </div>
                    <fieldset className="space-y-3 rounded-xl border border-slate-800 p-4">
                        <legend className="px-2 text-sm font-medium text-slate-200">Ìpìlẹ̀ credit</legend>
                        <Checkbox name="boilerplate_credit_visible" label='Show "Built on Ìpìlẹ̀" in the footer'
                            description="A small credit next to your copyright line. Turning it off does not affect your own brand."
                            checked={form.data.boilerplate_credit_visible} onChange={(checked) => form.setData('boilerplate_credit_visible', checked)} />
                        {form.errors.boilerplate_credit_visible && <p role="alert" className="text-sm text-rose-400">{form.errors.boilerplate_credit_visible}</p>}
                        <Checkbox name="about_page_visible" label='Show the "About this boilerplate" page'
                            description="A public page describing what Ìpìlẹ̀ includes. The footer credit links to it while both are on."
                            checked={form.data.about_page_visible} onChange={(checked) => form.setData('about_page_visible', checked)} />
                        {form.errors.about_page_visible && <p role="alert" className="text-sm text-rose-400">{form.errors.about_page_visible}</p>}
                    </fieldset>
                    <Button disabled={form.processing}>{form.processing ? 'Saving…' : 'Save branding'}</Button>
                </form>
            </Panel>
        </AdminLayout>
    );
}
