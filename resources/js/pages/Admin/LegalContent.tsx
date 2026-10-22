import { Link } from '@inertiajs/react';
import AdminLayout from '../../components/AdminLayout';
import type { LegalDocument } from '../../types';

export default function LegalContent({ documents }: { documents: LegalDocument[] }) {
    return <AdminLayout title="Legal pages">
        <p className="text-sm text-slate-400">Start with the supplied drafts, adapt them to your product, then publish. Draft edits do not change the public page.</p>
        <div className="grid gap-4 sm:grid-cols-2">
            {documents.map((document) => <Link key={document.slug} href={`/admin/legal/${document.slug}`} className="rounded-xl border border-slate-800 bg-slate-900 p-6 hover:border-emerald-500">
                <h2 className="font-semibold">{document.title}</h2>
                <p className="mt-2 text-sm text-slate-400">{document.version ? `Published v${document.version}` : 'Draft — not published'}</p>
                <span className="mt-4 inline-block text-sm text-emerald-400">Open editor →</span>
            </Link>)}
        </div>
    </AdminLayout>;
}
