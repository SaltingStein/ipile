import AppBrand from './AppBrand';
import { Link, usePage } from '@inertiajs/react';
import type { SharedPageProps } from '../types';
import { adminPermissions } from '../adminNavigation';
import { appNavigation } from '../appNavigation';

interface DashboardSidebarProps {
    open: boolean;
    onNavigate: () => void;
}

export default function DashboardSidebar({ open, onNavigate }: DashboardSidebarProps) {
    const { auth, navigation = [], navigationSettingsUrl, assistantEnabled = false, developerPortalVisible = false } = usePage<SharedPageProps>().props;
    const user = auth.user;
    const currentPath = usePage().url.split(/[?#]/)[0];
    const can = (permission: string) => Boolean(user?.is_super_admin || user?.permissions.includes(permission));
    const links = [
        { label: 'Dashboard', href: '/dashboard' },
        ...appNavigation.filter((item) => !item.permission || can(item.permission)).map(({ label, href }) => ({ label, href })),
        ...(user && !user.is_guest ? [{ label: 'Teams', href: '/teams' }, { label: 'Forms', href: '/forms' }, { label: 'Account security', href: '/settings/security' }] : []),
        ...(adminPermissions.some(can) ? [{ label: 'Admin', href: '/admin' }] : []),
    ];
    const developerLinks = user && !user.is_guest
        ? [
            { label: 'API tokens', href: '/settings/api-tokens' },
            ...(developerPortalVisible ? [{ label: 'Developer portal', href: '/developers' }] : []),
        ]
        : [];
    const helpCenterLinks = [
        { label: 'Help', href: '/help' },
        ...(assistantEnabled ? [{ label: 'Assistant', href: '/assistant' }] : []),
    ];
    const linkClasses = (href: string) => {
        const path = href.split(/[?#]/)[0];
        const active = currentPath === path || (path !== '/' && currentPath.startsWith(path + '/'));
        return `block rounded-lg px-3 py-2.5 text-sm transition focus-visible:outline-2 focus-visible:outline-emerald-400 ${active ? 'bg-emerald-500/10 font-semibold text-emerald-300' : 'text-slate-300 hover:bg-slate-800 hover:text-slate-50'}`;
    };

    return (
        <aside
            id="dashboard-sidebar"
            aria-label="Workspace navigation"
            className={`border-b border-slate-800 bg-slate-900/70 p-4 lg:col-start-1 lg:row-start-1 lg:row-span-3 lg:sticky lg:top-0 lg:block lg:h-screen lg:w-64 lg:shrink-0 lg:overflow-y-auto lg:border-r lg:border-b-0 lg:p-6 ${open ? 'block' : 'hidden'}`}
        >
            <Link href="/dashboard" onClick={onNavigate} className="mb-8 hidden text-lg font-semibold tracking-wide text-emerald-400 lg:block">
                <AppBrand />
            </Link>
            <nav aria-label="Main menu" className="space-y-1">
                {links.map((item) => (
                    <Link key={item.href} href={item.href} onClick={onNavigate}
                        aria-current={currentPath === item.href.split(/[?#]/)[0] ? 'page' : undefined}
                        className={linkClasses(item.href)}>
                        {item.label}
                    </Link>
                ))}
            </nav>
            {developerLinks.length > 0 && (
                <div className="mt-4 space-y-1 border-t border-slate-800 pt-4">
                    <p className="px-3 pb-1 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-500">Developers</p>
                    {developerLinks.map((item) => (
                        <Link key={item.href} href={item.href} onClick={onNavigate}
                            aria-current={currentPath === item.href.split(/[?#]/)[0] ? 'page' : undefined}
                            className={linkClasses(item.href)}>
                            {item.label}
                        </Link>
                    ))}
                </div>
            )}
            {helpCenterLinks.length > 0 && (
                <div className="mt-4 space-y-1 border-t border-slate-800 pt-4">
                    <p className="px-3 pb-1 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-500">Help Center</p>
                    {helpCenterLinks.map((item) => (
                        <Link key={item.href} href={item.href} onClick={onNavigate}
                            aria-current={currentPath === item.href.split(/[?#]/)[0] ? 'page' : undefined}
                            className={linkClasses(item.href)}>
                            {item.label}
                        </Link>
                    ))}
                </div>
            )}
            {navigation.length > 0 && (
                <div className="mt-8 space-y-3">
                    <h2 className="px-3 text-xs font-semibold uppercase tracking-wider text-slate-500">Explore</h2>
                    <nav aria-label="Custom menu" className="space-y-1">
                        {navigation.map((item) => (
                            <Link key={item.href} href={item.href} onClick={onNavigate}
                                aria-current={currentPath === item.href.split(/[?#]/)[0] ? 'page' : undefined}
                                className={linkClasses(item.href)}>
                                <span className="block break-words">{item.label}</span>
                            </Link>
                        ))}
                    </nav>
                </div>
            )}
            {navigationSettingsUrl && navigation.length === 0 && (
                <p className="mt-8 px-3 text-xs leading-relaxed text-slate-500">
                    Add links to your new pages from Admin → Menus.
                </p>
            )}
        </aside>
    );
}
