import { Link, usePage } from '@inertiajs/react';
import AdminLayout from '../../components/AdminLayout';
import Panel from '../../components/ui/Panel';
import { adminNavigation } from '../../adminNavigation';
import type { SharedPageProps } from '../../types';

export default function Overview() {
    const { auth } = usePage<SharedPageProps>().props;
    const can = (permission: string) => Boolean(auth.user?.is_super_admin || auth.user?.permissions.includes(permission));
    const groups = adminNavigation(can);

    return <AdminLayout title="Admin overview">
        <p className="text-sm text-slate-400">Every admin page you can open, grouped by what it controls.</p>
        {groups.map((group) => <Panel key={group.title} title={group.title} description={group.description}>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {group.sections.map((section) => <Link key={section.href} href={section.href} className="rounded-xl border border-slate-800 bg-slate-950/60 p-4 transition hover:border-emerald-500 focus-visible:outline-2 focus-visible:outline-emerald-400">
                    <h3 className="font-semibold text-slate-50">{section.title}</h3>
                    <p className="mt-1.5 text-sm text-slate-400">{section.description}</p>
                </Link>)}
            </div>
        </Panel>)}
    </AdminLayout>;
}
