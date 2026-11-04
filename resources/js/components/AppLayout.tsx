import AppBrand from './AppBrand';
import BrandFooter from './BrandFooter';
import AssistantBubble from './AssistantBubble';
import AssistantWidget from './AssistantWidget';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import StatusMessage from './ui/StatusMessage';
import DashboardSidebar from './DashboardSidebar';
import type { SharedPageProps } from '../types';

/**
 * Signed-in application shell: navigation, account banners and flashed status messages.
 */
interface AppLayoutProps {
    title: string;
    children: ReactNode;
}

export default function AppLayout({ title, children }: AppLayoutProps) {
    const { auth, teams, status, branding, assistantWidget } = usePage<SharedPageProps>().props;
    const user = auth.user;
    const { url } = usePage();
    const [sidebarOpen, setSidebarOpen] = useState(false);

    useEffect(() => setSidebarOpen(false), [url]);

    function switchTeam(teamId: string) {
        router.put('/current-team', { team_id: Number(teamId) }, { preserveScroll: true });
    }


    return (
        <div className="min-h-screen bg-slate-950 text-slate-100 lg:grid lg:grid-cols-[16rem_minmax(0,1fr)] lg:grid-rows-[auto_1fr_auto]" onKeyDown={(event) => { if (event.key === 'Escape') setSidebarOpen(false); }}>
            <Head title={`${title} | ${branding.name}`} />
            <header className="border-b border-slate-800 lg:col-start-2 lg:row-start-1">
                <nav className="mx-auto flex max-w-5xl flex-wrap items-center gap-3 px-4 py-4 text-sm sm:gap-6 sm:px-6">
                    <button type="button" aria-expanded={sidebarOpen} aria-controls="dashboard-sidebar"
                        onClick={() => setSidebarOpen((open) => !open)}
                        className="rounded-lg border border-slate-700 px-3 py-2 text-slate-200 focus-visible:outline-2 focus-visible:outline-emerald-400 lg:hidden">
                        {sidebarOpen ? 'Close menu' : 'Menu'}
                    </button>
                    <Link href="/dashboard" className="font-semibold tracking-wide text-emerald-400 lg:hidden"><AppBrand /></Link>
                    <span className="hidden font-semibold text-slate-50 lg:block">{title}</span>
                    {teams.length > 0 && (
                        <label className="ml-auto flex items-center gap-2 text-slate-400">
                            <span className="sr-only">Current team</span>
                            <select
                                value={user?.current_team_id ?? ''}
                                onChange={(event) => switchTeam(event.target.value)}
                                className="rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-slate-100"
                            >
                                {user?.current_team_id === null && <option value="">Choose a team</option>}
                                {teams.map((team) => (
                                    <option key={team.id} value={team.id}>
                                        {team.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    <span className={teams.length > 0 ? 'text-slate-400' : 'ml-auto text-slate-400'}>{user?.name}</span>
                    <button type="button" onClick={() => router.post('/logout')} className="text-slate-300 hover:text-slate-50">
                        Log out
                    </button>
                </nav>
            </header>
            <DashboardSidebar open={sidebarOpen} onNavigate={() => setSidebarOpen(false)} />
            <main className="mx-auto flex w-full min-w-0 max-w-5xl flex-col gap-6 px-4 py-8 sm:px-6 lg:col-start-2 lg:row-start-2">
                {user?.is_guest && (
                    <p className="rounded-lg border border-slate-700 bg-slate-900 px-4 py-3 text-sm text-slate-300">
                        You are using a guest account.{' '}
                        <Link href="/register" className="font-semibold text-emerald-400 hover:text-emerald-300">
                            Create an account
                        </Link>{' '}
                        to keep your activity and sign in from other devices.
                    </p>
                )}
                {user && !user.is_guest && !user.email_verified && (
                    <p className="rounded-lg border border-amber-800 bg-amber-950/40 px-4 py-3 text-sm text-amber-200">
                        Please verify your email address.{' '}
                        <Link href="/email/verify" className="font-semibold underline">
                            Resend the link
                        </Link>
                    </p>
                )}
                <StatusMessage message={status} />
                {children}
            </main>
            <BrandFooter className="border-t border-slate-800 lg:col-start-2 lg:row-start-3" />
            <AssistantWidget config={user && !user.is_guest ? assistantWidget : null} />
            <AssistantBubble />
        </div>
    );
}
