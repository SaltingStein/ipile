import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../components/AppLayout';
import Button from '../components/ui/Button';
import EmptyState from '../components/ui/EmptyState';
import Panel from '../components/ui/Panel';
import StatTile from '../components/ui/StatTile';
import type { SharedPageProps } from '../types';

/**
 * The signed-in home page. This file is yours: replace any section. Data comes from app/Http/Controllers/DashboardController.php.
 */
interface DashboardProps {
    stats: {
        teams: number;
        api_tokens: number;
        two_factor_enabled: boolean;
        member_since: string | null;
    };
    showDeveloperChecklist: boolean;
}

const checklistDismissedKey = 'ipile.developer-checklist.dismissed';

export default function Dashboard({ stats, showDeveloperChecklist }: DashboardProps) {
    const { auth } = usePage<SharedPageProps>().props;
    const userName = auth.user?.name || 'Guest';
    const [checklistDismissed, setChecklistDismissed] = useState(() => typeof window !== 'undefined' && window.localStorage.getItem(checklistDismissedKey) === '1');

    const dismissChecklist = () => {
        window.localStorage.setItem(checklistDismissedKey, '1');
        setChecklistDismissed(true);
    };

    return (
        <AppLayout title="Dashboard">
            <h1 className="text-2xl font-bold">Welcome back, {userName}</h1>

            {showDeveloperChecklist && !checklistDismissed && (
                <Panel title="Make this app yours" description="Only shown while the app runs locally. Dismiss it once you are set up.">
                    <ol className="list-decimal space-y-2 pl-5 text-sm text-slate-300">
                        <li>Edit this page in <code className="text-emerald-300">resources/js/pages/Dashboard.tsx</code>; its data comes from <code className="text-emerald-300">app/Http/Controllers/DashboardController.php</code>.</li>
                        <li>Add a page with <code className="text-emerald-300">php artisan ipile:page Reports --nav</code>. It creates the page, its route in <code className="text-emerald-300">routes/app.php</code>, a sidebar link, a test and a draft help article.</li>
                        <li>Set your app name and logo in <Link href="/admin/branding" className="text-emerald-300 hover:text-emerald-200">Admin → Branding</Link>, and colours in <Link href="/admin/theme" className="text-emerald-300 hover:text-emerald-200">Admin → Theme</Link>.</li>
                        <li>Connect payments, SMS and email in <Link href="/admin/integrations" className="text-emerald-300 hover:text-emerald-200">Admin → Integrations</Link>.</li>
                        <li>Everything else, as short recipes: <code className="text-emerald-300">project-docs/developer-guide.md</code>.</li>
                    </ol>
                    <Button type="button" variant="secondary" className="self-start" onClick={dismissChecklist}>Dismiss</Button>
                </Panel>
            )}

            {/* Example: headline numbers passed from DashboardController. Swap in your product's own. */}
            <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile label="Teams" value={stats.teams} />
                <StatTile label="API tokens" value={stats.api_tokens} />
                <StatTile label="Two-factor" value={stats.two_factor_enabled ? 'On' : 'Off'} />
                <StatTile label="Member since" value={stats.member_since ? new Date(stats.member_since).toLocaleDateString() : '—'} />
            </dl>

            {/* Replace with your product's main content. */}
            <EmptyState title="Your workspace" description="This is where your product's main content goes." />
        </AppLayout>
    );
}
