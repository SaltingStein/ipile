import { useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import Button from './ui/Button';
import Checkbox from './ui/Checkbox';
import type { SharedPageProps } from '../types';

export default function ConsentManager() {
    const { consent, legalLinks } = usePage<SharedPageProps>().props;
    const { config, choice } = consent;
    const { component } = usePage();
    const isLandingPage = component.startsWith('Landing/');
    const dialog = useRef<HTMLDialogElement>(null);
    const form = useForm({ version: config.version, analytics: choice?.analytics ?? false, marketing: choice?.marketing ?? false });

    useEffect(() => {
        const open = () => { if (!dialog.current?.open) dialog.current?.showModal(); };
        window.addEventListener('open-consent-preferences', open);
        if (config.enabled && !choice && isLandingPage) open();
        return () => window.removeEventListener('open-consent-preferences', open);
    }, [config.enabled, config.version, choice?.id, isLandingPage]);

    const save = (analytics: boolean, marketing: boolean) => {
        form.transform(() => ({ version: config.version, analytics, marketing }));
        form.post('/consent', {
            preserveScroll: true,
            onSuccess: () => { dialog.current?.close(); window.location.reload(); },
        });
    };

    return <dialog ref={dialog} aria-labelledby="consent-heading"
        onCancel={(event) => { if (isLandingPage && !choice) event.preventDefault(); }}
        className="m-auto max-h-[90dvh] w-[calc(100%_-_2rem)] max-w-xl overflow-y-auto rounded-2xl border border-slate-700 bg-slate-950 p-6 text-left text-slate-100 backdrop:bg-black/70">
        <div className="space-y-5">
            <h2 id="consent-heading" className="text-xl font-semibold">Privacy preferences</h2>
            {config.notices.filter((notice) => notice.enabled).map((notice) => <section key={notice.id}>
                <h3 className="font-semibold">{notice.title}</h3>
                <p className="mt-2 whitespace-pre-wrap text-sm leading-6 text-slate-300">{notice.body}</p>
            </section>)}
            <p className="text-sm text-slate-400">Necessary cookies are always on for sign-in, security and remembering these preferences.</p>
            {config.analytics_enabled && <Checkbox name="visitor-analytics" label="Analytics cookies" checked={form.data.analytics} onChange={(value) => form.setData('analytics', value)} />}
            {config.marketing_enabled && <Checkbox name="visitor-marketing" label="Marketing cookies" checked={form.data.marketing} onChange={(value) => form.setData('marketing', value)} />}
            <div className="flex flex-wrap gap-3">
                {legalLinks.map((link) => <a key={link.href} href={link.href} target="_blank" rel="noreferrer" className="text-xs text-emerald-400 underline">{link.title}</a>)}
            </div>
            {Object.values(form.errors).map((error) => <p key={error} role="alert" className="text-sm text-rose-400">{error}</p>)}
            <div className="flex flex-wrap gap-3">
                <Button type="button" variant="secondary" disabled={form.processing} onClick={() => save(false, false)}>{config.reject_label}</Button>
                <Button type="button" variant="secondary" disabled={form.processing} onClick={() => save(config.analytics_enabled, config.marketing_enabled)}>{config.accept_label}</Button>
                <Button type="button" disabled={form.processing} onClick={() => save(config.analytics_enabled && form.data.analytics, config.marketing_enabled && form.data.marketing)}>{config.save_label}</Button>
                {(!isLandingPage || choice) && <Button type="button" variant="secondary" disabled={form.processing} onClick={() => dialog.current?.close()}>Close</Button>}
            </div>
        </div>
    </dialog>;
}
