import { useEffect, useRef, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import PublicLayout from '../../components/PublicLayout';
import FormFields from '../../components/forms/FormFields';
import Button from '../../components/ui/Button';
import StatusMessage from '../../components/ui/StatusMessage';
import type { FormAnswer, FormFieldDefinition, FormLayoutValue, FormMedia } from '../../types';

interface PublicFormProps {
    form: {
        title: string;
        description: string | null;
        fields: FormFieldDefinition[];
        submit_label: string;
        submit_url: string;
        layout: FormLayoutValue;
        media: FormMedia | null;
        closes_at: string | null;
        places_left: number | null;
    };
    closedReason: string | null;
    successMessage: string | null;
    honeypot: string;
}

export default function PublicForm({ form, closedReason, successMessage, honeypot }: PublicFormProps) {
    const body = (
        <FormBody form={form} closedReason={closedReason} successMessage={successMessage} honeypot={honeypot} />
    );

    return (
        <PublicLayout title={form.title}>
            {form.layout === 'designed' ? <DesignedPage form={form}>{body}</DesignedPage> : <SimplePage form={form}>{body}</SimplePage>}
        </PublicLayout>
    );
}

/**
 * A clean, centred column; the banner (if any) sits above the title.
 */
function SimplePage({ form, children }: { form: PublicFormProps['form']; children: ReactNode }) {
    return (
        <div className="mx-auto flex w-full max-w-2xl flex-col gap-6 px-6 py-12">
            {form.media?.type === 'image' && (
                <img src={form.media.url} alt="" className="aspect-[3/1] w-full rounded-2xl object-cover" />
            )}
            {form.media?.type === 'video' && (
                <video src={form.media.url} controls playsInline preload="metadata" className="aspect-video w-full rounded-2xl bg-black" />
            )}
            <header className="flex flex-col gap-2">
                <h1 className="text-3xl font-bold text-slate-50">{form.title}</h1>
                {form.description && <p className="whitespace-pre-line text-slate-300">{form.description}</p>}
            </header>
            {children}
        </div>
    );
}

/**
 * A full-width banner or background video behind the title, with the details beside the form.
 */
function DesignedPage({ form, children }: { form: PublicFormProps['form']; children: ReactNode }) {
    const closesAt = form.closes_at ? new Date(form.closes_at) : null;
    const hasDetails = closesAt !== null || form.places_left !== null;

    return (
        <>
            <section className="relative isolate flex min-h-[22rem] items-end overflow-hidden bg-gradient-to-br from-emerald-900 via-slate-900 to-slate-950 sm:min-h-[28rem]">
                {form.media?.type === 'image' && <img src={form.media.url} alt="" className="absolute inset-0 -z-20 size-full object-cover" />}
                {form.media?.type === 'video' && <BackgroundVideo url={form.media.url} />}
                <div aria-hidden="true" className="absolute inset-0 -z-10 bg-gradient-to-t from-slate-950 via-slate-950/70 to-slate-950/10" />
                <div className="mx-auto w-full max-w-6xl px-6 pb-10 pt-24">
                    <h1 className="max-w-3xl text-4xl font-bold tracking-tight text-white drop-shadow sm:text-5xl">{form.title}</h1>
                </div>
            </section>
            <div className="mx-auto grid w-full max-w-6xl gap-8 px-6 py-10 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]">
                <aside className="flex flex-col gap-5 lg:sticky lg:top-6 lg:self-start">
                    {form.description && <p className="whitespace-pre-line text-lg leading-relaxed text-slate-300">{form.description}</p>}
                    {hasDetails && (
                        <dl className="grid grid-cols-2 gap-3">
                            {closesAt && (
                                <div className="rounded-xl border border-slate-800 bg-slate-900/80 px-4 py-3">
                                    <dt className="text-xs uppercase tracking-wide text-slate-400">Closes</dt>
                                    <dd className="mt-1 font-semibold text-slate-50">
                                        <time dateTime={form.closes_at ?? undefined}>{closesAt.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })}</time>
                                    </dd>
                                </div>
                            )}
                            {form.places_left !== null && (
                                <div className="rounded-xl border border-slate-800 bg-slate-900/80 px-4 py-3">
                                    <dt className="text-xs uppercase tracking-wide text-slate-400">Places left</dt>
                                    <dd className="mt-1 font-semibold tabular-nums text-slate-50">{form.places_left.toLocaleString()}</dd>
                                </div>
                            )}
                        </dl>
                    )}
                </aside>
                <div>{children}</div>
            </div>
        </>
    );
}

/**
 * Muted, looping banner video. People who ask for reduced motion get a paused video with controls instead.
 */
function BackgroundVideo({ url }: { url: string }) {
    const video = useRef<HTMLVideoElement>(null);
    const [reducedMotion, setReducedMotion] = useState(false);

    useEffect(() => {
        const query = window.matchMedia('(prefers-reduced-motion: reduce)');
        setReducedMotion(query.matches);

        if (query.matches) {
            video.current?.pause();
        }
    }, []);

    return (
        <video ref={video} src={url} autoPlay={!reducedMotion} muted loop playsInline preload="metadata" controls={reducedMotion}
            aria-hidden={!reducedMotion} className="absolute inset-0 -z-20 size-full object-cover" />
    );
}

function FormBody({ form, closedReason, successMessage, honeypot }: PublicFormProps) {
    const { data, setData, post, processing, errors, transform } = useForm<{ answers: Record<string, FormAnswer>; trap: string }>({
        answers: {},
        trap: '',
    });
    const fieldErrors = errors as Record<string, string | undefined>;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        transform(({ answers, trap }) => ({ answers, [honeypot]: trap }));
        post(form.submit_url);
    }

    if (successMessage) {
        return (
            <div role="status" className="rounded-2xl border border-emerald-800 bg-emerald-950/80 p-6 text-emerald-100">
                <p className="whitespace-pre-line">{successMessage}</p>
            </div>
        );
    }

    if (closedReason) {
        return <StatusMessage tone="error" message={closedReason} />;
    }

    return (
        <form onSubmit={submit} noValidate className="relative flex flex-col gap-5 rounded-2xl border border-slate-800 bg-slate-900 p-6 shadow-xl shadow-black/20">
            <StatusMessage tone="error" message={fieldErrors.form} />
            {Object.keys(fieldErrors).some((key) => key.startsWith('answers.')) && (
                <StatusMessage tone="error" message="Please check the highlighted answers." />
            )}
            <FormFields fields={form.fields} answers={data.answers} errors={fieldErrors} idPrefix="answer"
                onChange={(key, value) => setData('answers', { ...data.answers, [key]: value })} />
            {/* Spam trap: hidden from people and screen readers, filled in by bots. */}
            <div aria-hidden="true" className="absolute -left-[9999px] size-px overflow-hidden">
                <label htmlFor={honeypot}>Leave this empty</label>
                <input id={honeypot} name={honeypot} type="text" tabIndex={-1} autoComplete="off"
                    value={data.trap} onChange={(event) => setData('trap', event.target.value)} />
            </div>
            <p className="text-xs text-slate-400"><span className="text-rose-400">*</span> Required</p>
            <Button disabled={processing} className="self-start">{processing ? 'Sending…' : form.submit_label}</Button>
        </form>
    );
}
