import type { FormEvent } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import PublicLayout from '../../components/PublicLayout';
import CampaignPageView from '../../components/campaigns/CampaignPageView';
import type { CampaignFormData, CampaignPageData } from '../../components/campaigns/types';
import FormFields from '../../components/forms/FormFields';
import Button from '../../components/ui/Button';
import StatusMessage from '../../components/ui/StatusMessage';
import type { FormAnswer } from '../../types';

interface CampaignShowProps {
    page: CampaignPageData;
    isPreview: boolean;
    editUrl: string | null;
    submitUrl: string;
    clickUrl: string;
    submitted: boolean;
    honeypot: string;
}

export default function CampaignShow({ page, isPreview, editUrl, submitUrl, clickUrl, submitted, honeypot }: CampaignShowProps) {
    // Counted in the background so following the link is never delayed. Drafts are not counted.
    const countClick = isPreview ? undefined : () => navigator.sendBeacon?.(clickUrl);

    return (
        <PublicLayout title={page.title}>
            <Head>
                {page.meta_description && <meta name="description" content={page.meta_description} />}
                <meta property="og:type" content="website" />
                <meta property="og:title" content={page.title} />
                {page.meta_description && <meta property="og:description" content={page.meta_description} />}
                {page.cover_url && <meta property="og:image" content={page.cover_url} />}
            </Head>
            {(isPreview || editUrl) && (
                <p role="status" className={`border-b px-6 py-3 text-center text-sm ${isPreview ? 'border-amber-800 bg-amber-950/60 text-amber-100' : 'border-slate-800 bg-slate-900 text-slate-300'}`}>
                    {isPreview ? 'Preview: this page is a draft, so only campaign managers can see it.' : 'You manage this page.'}
                    {editUrl && <> <Link href={editUrl} className="font-semibold underline underline-offset-4">Edit page</Link></>}
                </p>
            )}
            <CampaignPageView page={page} onButtonClick={countClick}
                renderForm={(form) => <CampaignForm form={form} submitUrl={submitUrl} submitted={submitted} honeypot={honeypot} />} />
        </PublicLayout>
    );
}

function CampaignForm({ form, submitUrl, submitted, honeypot }: { form: CampaignFormData; submitUrl: string; submitted: boolean; honeypot: string }) {
    const { data, setData, post, processing, errors, transform } = useForm<{ answers: Record<string, FormAnswer>; trap: string }>({ answers: {}, trap: '' });
    const fieldErrors = errors as Record<string, string | undefined>;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        transform(({ answers, trap }) => ({ answers, [honeypot]: trap }));
        post(submitUrl, { preserveScroll: true });
    }

    if (submitted) {
        return (
            <div role="status" className="rounded-2xl border border-emerald-800 bg-emerald-950/80 p-6 text-emerald-100">
                <p className="whitespace-pre-line">{form.success_message}</p>
            </div>
        );
    }

    if (form.closed_reason) {
        return <StatusMessage tone="error" message={form.closed_reason} />;
    }

    return (
        <form onSubmit={submit} noValidate className="relative flex flex-col gap-5">
            <StatusMessage tone="error" message={fieldErrors.form} />
            {Object.keys(fieldErrors).some((key) => key.startsWith('answers.')) && <StatusMessage tone="error" message="Please check the highlighted answers." />}
            {form.places_left !== null && <p className="text-sm text-slate-400">{form.places_left.toLocaleString()} places left</p>}
            <FormFields fields={form.fields} answers={data.answers} errors={fieldErrors} idPrefix="campaign-answer"
                onChange={(key, value) => setData('answers', { ...data.answers, [key]: value })} />
            {/* Spam trap: hidden from people and screen readers, filled in by bots. */}
            <div aria-hidden="true" className="absolute -left-[9999px] size-px overflow-hidden">
                <label htmlFor={`campaign-${honeypot}`}>Leave this empty</label>
                <input id={`campaign-${honeypot}`} type="text" tabIndex={-1} autoComplete="off" value={data.trap} onChange={(event) => setData('trap', event.target.value)} />
            </div>
            <Button disabled={processing} className="self-start !bg-[var(--accent)] !text-slate-950">{processing ? 'Sending…' : form.submit_label}</Button>
        </form>
    );
}
