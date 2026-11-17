import { usePage } from '@inertiajs/react';
import PublicLayout from '../../components/PublicLayout';
import type { SharedPageProps } from '../../types';
import GuestStartForm from '../../components/GuestStartForm';
import LandingAuthLinks from '../../components/LandingAuthLinks';

/**
 * "Fintech Clean" landing page: headline card. Colours come from the site theme.
 */
export default function LayoutAlpha() {
    const { branding } = usePage<SharedPageProps>().props;
    return (
        <PublicLayout title={branding.name}>
            <main className="mx-auto w-full flex-1 max-w-6xl px-6 py-20">
                <div className="rounded-2xl border border-slate-800 bg-slate-900/80 p-8 shadow-2xl shadow-slate-950/30">
                    <h1 className="text-4xl font-bold md:text-6xl">Modern money rails for West African products.</h1>
                    <p className="mt-6 max-w-2xl text-lg text-slate-300">
                        Launch faster with a secure, flexible base built for fintech, SaaS, and digital consumer products.
                    </p>
                    <div className="mt-10 flex flex-wrap gap-4">
                        <LandingAuthLinks dashboardLabel="Go to dashboard"
                            primaryClassName="rounded-full bg-emerald-500 px-6 py-3 font-medium text-slate-950 transition hover:bg-emerald-400"
                            secondaryClassName="rounded-full border border-slate-700 px-6 py-3 font-medium text-slate-100 transition hover:border-slate-500" />
                        <GuestStartForm
                            buttonLabel="Continue as guest"
                            inputClassName="rounded-full border border-slate-700 bg-slate-950 px-5 py-3 text-slate-100 placeholder:text-slate-500 focus:border-emerald-500 focus:outline-none"
                            buttonClassName="rounded-full border border-slate-700 px-6 py-3 font-medium text-slate-100 transition hover:border-slate-500"
                            errorClassName="text-sm text-rose-400"
                        />
                    </div>
                </div>
            </main>
        </PublicLayout>
    );
}
