import AppBrand from './AppBrand';
import AssistantBubble from './AssistantBubble';
import BrandFooter from './BrandFooter';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import type { SharedPageProps } from '../types';
import { adminNavigation } from '../adminNavigation';
import StatusMessage from './ui/StatusMessage';

interface AdminLayoutProps {
    title: string;
    children: ReactNode;
}

export default function AdminLayout({ title, children }: AdminLayoutProps) {
    const { auth, status, branding } = usePage<SharedPageProps>().props;
    const user = auth.user;
    const currentPath = usePage().url.split(/[?#]/)[0];
    const can = (permission: string) => Boolean(user?.is_super_admin || user?.permissions.includes(permission));

    const groups = adminNavigation(can);
    const overviewHref = '/admin';
    const matches = (href: string) => currentPath === href || (href !== overviewHref && currentPath.startsWith(href + '/'));
    const activeHref = [overviewHref, ...groups.flatMap((group) => group.sections.map((section) => section.href))]
        .filter(matches)
        .sort((first, second) => second.length - first.length)[0];
    const activeGroupTitle = groups.find((group) => group.sections.some((section) => section.href === activeHref))?.title;
    const [openGroups, setOpenGroups] = useState<string[]>(activeGroupTitle ? [activeGroupTitle] : []);
    const toggleGroup = (groupTitle: string) => setOpenGroups((open) => open.includes(groupTitle)
        ? open.filter((openTitle) => openTitle !== groupTitle)
        : [...open, groupTitle]);
    const linkClasses = (href: string) => `block rounded-md px-3 py-1.5 text-sm transition focus-visible:outline-2 focus-visible:outline-emerald-400 ${href === activeHref ? 'bg-emerald-500/10 font-semibold text-emerald-300' : 'text-slate-300 hover:bg-slate-800 hover:text-slate-50'}`;

    return (
        <div className="flex min-h-screen flex-col bg-slate-950 text-slate-100">
            <Head title={`${title} | ${branding.name}`} />
            <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-6 lg:flex-row">
                <aside className="w-full shrink-0 rounded-2xl border border-slate-800 bg-slate-900/80 p-4 lg:max-w-xs">
                    <div className="mb-6 flex items-center justify-between">
                        <div>
                            <div className="text-sm font-semibold text-emerald-400"><AppBrand /></div>
                            <h2 className="mt-2 text-xl font-semibold text-slate-50">Admin</h2>
                        </div>
                    </div>

                    <nav aria-label="Admin menu" className="flex flex-col gap-1">
                        <Link href={overviewHref} aria-current={currentPath === overviewHref ? 'page' : undefined} className={linkClasses(overviewHref)}>
                            Overview
                        </Link>

                        {groups.map((group, index) => {
                            const isOpen = openGroups.includes(group.title);
                            const holdsCurrentPage = group.title === activeGroupTitle;

                            return (
                                <div key={group.title} className="flex flex-col gap-1">
                                    <h3>
                                        <button type="button" aria-expanded={isOpen} aria-controls={`admin-menu-group-${index}`} onClick={() => toggleGroup(group.title)}
                                            className={`flex w-full items-center justify-between rounded-md px-3 py-2 text-xs font-semibold uppercase tracking-wider transition hover:bg-slate-800 hover:text-slate-200 focus-visible:outline-2 focus-visible:outline-emerald-400 ${holdsCurrentPage ? 'text-emerald-300' : 'text-slate-400'}`}>
                                            {group.title}
                                            <svg aria-hidden="true" viewBox="0 0 20 20" fill="currentColor" className={`h-4 w-4 transition-transform ${isOpen ? 'rotate-90' : ''}`}>
                                                <path fillRule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clipRule="evenodd" />
                                            </svg>
                                        </button>
                                    </h3>
                                    <div id={`admin-menu-group-${index}`} hidden={!isOpen} className="ml-3 border-l border-slate-800 pl-2">
                                        {group.sections.map((section) => (
                                            <Link key={section.href} href={section.href} aria-current={currentPath === section.href ? 'page' : undefined} className={linkClasses(section.href)}>
                                                {section.title}
                                            </Link>
                                        ))}
                                    </div>
                                </div>
                            );
                        })}

                        <Link href="/dashboard" className="mt-4 rounded-lg border border-slate-800 px-3 py-2 text-sm text-slate-300 transition hover:border-slate-600 hover:text-slate-50">
                            Back to dashboard
                        </Link>
                    </nav>
                </aside>

                <main className="min-w-0 flex-1 space-y-6">
                    <header className="rounded-2xl border border-slate-800 bg-slate-900/80 px-5 py-4">
                        <p className="text-xs uppercase tracking-[0.2em] text-slate-400">Workspace</p>
                        <h1 className="mt-2 text-2xl font-semibold text-slate-50">{title}</h1>
                    </header>

                    <StatusMessage message={status} />
                    {children}
                </main>
            </div>
            <BrandFooter />
            <AssistantBubble />
        </div>
    );
}
