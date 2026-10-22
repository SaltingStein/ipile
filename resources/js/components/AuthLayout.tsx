import AppBrand from './AppBrand';
import BrandFooter from './BrandFooter';
import type { ReactNode } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import StatusMessage from './ui/StatusMessage';
import type { SharedPageProps } from '../types';

/**
 * Centered card used by every sign-in, sign-up and recovery page.
 */
interface AuthLayoutProps {
    title: string;
    description?: string;
    children: ReactNode;
}

export default function AuthLayout({ title, description, children }: AuthLayoutProps) {
    const { status, errors, branding } = usePage<SharedPageProps>().props;

    return (
        <main className="flex min-h-screen items-center justify-center bg-slate-950 px-6 py-12 text-slate-100">
            <Head title={`${title} | ${branding.name}`} />
            <div className="flex w-full max-w-md flex-col gap-6">
                <Link href="/" className="text-center text-sm uppercase tracking-[0.25em] text-emerald-400">
                    <AppBrand />
                </Link>
                <div className="flex flex-col gap-6 rounded-2xl border border-slate-800 bg-slate-900/80 p-8 shadow-2xl shadow-slate-950/30">
                    <div className="flex flex-col gap-2">
                        <h1 className="text-2xl font-bold">{title}</h1>
                        {description && <p className="text-sm text-slate-400">{description}</p>}
                    </div>
                    <StatusMessage message={status} />
                    <StatusMessage message={errors.oauth} tone="error" />
                    {children}
                </div>
                <BrandFooter />
            </div>
        </main>
    );
}
