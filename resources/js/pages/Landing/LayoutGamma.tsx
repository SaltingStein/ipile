import { usePage } from '@inertiajs/react';
import PublicLayout from '../../components/PublicLayout';
import type { SharedPageProps } from '../../types';
import GuestStartForm from '../../components/GuestStartForm';

/**
 * "Minimal Light" landing page: text-first, no panels. Colours come from the site theme.
 */
export default function LayoutGamma() {
    const { branding } = usePage<SharedPageProps>().props;
    return (
        <PublicLayout title={branding.name}>
            <main className="mx-auto w-full flex-1 max-w-3xl px-6 py-24">
                <div className="space-y-6">
                    <h1 className="text-4xl font-bold tracking-tight md:text-6xl">Build products that ship with clarity.</h1>
                    <p className="max-w-xl text-lg text-slate-300">
                        A lean foundation for digital products, backend tooling, and first-party experiences.
                    </p>
                    <div className="flex flex-wrap gap-4 pt-4">
                        <a href="/dashboard" className="rounded-full bg-slate-50 px-6 py-3 font-medium text-slate-950 hover:bg-slate-200">Open dashboard</a>
                        <GuestStartForm
                            buttonLabel="Start instantly"
                            inputClassName="rounded-full border border-slate-700 bg-slate-900 px-5 py-3 text-slate-100 placeholder:text-slate-500 focus:border-slate-50 focus:outline-none"
                            buttonClassName="rounded-full border border-slate-700 px-6 py-3 font-medium text-slate-100 hover:border-slate-500"
                            errorClassName="text-sm text-rose-400"
                        />
                    </div>
                </div>
            </main>
        </PublicLayout>
    );
}
