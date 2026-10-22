import { Head, Link, usePage } from '@inertiajs/react';
import AppBrand from '../components/AppBrand';
import BrandFooter from '../components/BrandFooter';
import type { SharedPageProps } from '../types';

export default function AboutBoilerplate() {
    const { branding } = usePage<SharedPageProps>().props;

    return (
        <main className="flex min-h-screen flex-col bg-slate-950 text-slate-100 antialiased">
            <Head title={`About this boilerplate | ${branding.name}`} />
            <div className="mx-auto w-full max-w-4xl flex-1 px-6 py-14">
                <Link href="/" className="text-sm uppercase tracking-[0.25em] text-emerald-400">
                    <AppBrand />
                </Link>

                <div className="mt-8 space-y-6 rounded-2xl border border-slate-800 bg-slate-900/80 p-8">
                    <h1 className="text-3xl font-bold">About this boilerplate</h1>
                    <p className="text-slate-300">
                        {branding.name} is built on Ìpìlẹ̀, a Laravel boilerplate that starts you with authentication, team features, API readiness, integrations, and configurable admin tooling.
                        It is designed so product teams can shape behavior from the dashboard without shipping code for every operational tweak.
                    </p>

                    <section className="space-y-3">
                        <h2 className="text-xl font-semibold">How to make it yours</h2>
                        <ul className="list-disc space-y-2 pl-6 text-slate-300">
                            <li>Set your brand name, version and logo in Admin → Branding.</li>
                            <li>Pick your colours and landing page layout in Admin → Theme.</li>
                            <li>Add your own sidebar links in Admin → Menus.</li>
                            <li>Enable integrations (payments, SMS, mail) in Admin → Integrations.</li>
                            <li>Publish your legal pages and consent notices before launch.</li>
                        </ul>
                    </section>

                    <div className="rounded-lg border border-slate-700 bg-slate-950/60 p-4 text-sm text-slate-300">
                        Admins can hide this page and the "Built on Ìpìlẹ̀" footer credit at any time from Admin → Branding.
                    </div>
                </div>
            </div>
            <BrandFooter />
        </main>
    );
}

