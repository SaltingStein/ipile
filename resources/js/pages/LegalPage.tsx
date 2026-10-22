import { usePage } from '@inertiajs/react';
import PublicLayout from '../components/PublicLayout';
import type { SharedPageProps } from '../types';

export default function LegalPage({ document }: { document: { title: string; body: string; version: number; published_at: string } }) {
    const { branding } = usePage<SharedPageProps>().props;
    return <PublicLayout title={`${document.title} | ${branding.name}`}>
        <main className="mx-auto w-full max-w-3xl flex-1 space-y-6 px-6 py-10">
            <h1 className="text-3xl font-semibold">{document.title}</h1>
            <p className="text-sm text-slate-400">Version {document.version} · Last updated {new Date(document.published_at).toLocaleDateString()}</p>
            <article className="whitespace-pre-wrap break-words leading-8 text-slate-300">{document.body}</article>
        </main>
    </PublicLayout>;
}
