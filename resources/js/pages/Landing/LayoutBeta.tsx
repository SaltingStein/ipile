import { usePage } from '@inertiajs/react';
import PublicLayout from '../../components/PublicLayout';
import type { SharedPageProps } from '../../types';
import GuestStartForm from '../../components/GuestStartForm';

/**
 * "Bold Dark" landing page: centred, high-impact hero with a glowing panel. Colours come from the site theme.
 */
export default function LayoutBeta() {
    const { branding } = usePage<SharedPageProps>().props;
    return (
        <PublicLayout title={branding.name}>
            <main className="mx-auto flex w-full flex-1 max-w-5xl flex-col items-center justify-center px-6 py-24 text-center">
                <p className="text-xs font-semibold uppercase tracking-[0.4em] text-emerald-400">Built for momentum</p>
                <h1 className="mt-6 text-5xl font-black uppercase tracking-tight md:text-7xl">Move fast. Stay in control.</h1>
                <p className="mt-6 max-w-2xl text-lg text-slate-300">
                    Built for products that reward speed, retention, and community-driven momentum.
                </p>
                <div className="mt-12 w-full max-w-2xl rounded-3xl border border-emerald-500/30 bg-slate-900/80 p-8 shadow-2xl shadow-emerald-500/20">
                    <div className="flex flex-wrap items-start justify-center gap-4">
                        <a href="/dashboard" className="rounded-full bg-emerald-400 px-6 py-3 font-semibold text-slate-950 hover:bg-emerald-300">Launch app</a>
                        <GuestStartForm
                            buttonLabel="Quick start"
                            inputClassName="rounded-full border border-emerald-500/40 bg-slate-950 px-5 py-3 text-slate-100 placeholder:text-slate-500 focus:border-emerald-300 focus:outline-none"
                            buttonClassName="rounded-full border border-emerald-500/40 px-6 py-3 font-semibold text-slate-100 hover:border-emerald-300"
                            errorClassName="text-sm text-rose-400"
                        />
                    </div>
                </div>
            </main>
        </PublicLayout>
    );
}
