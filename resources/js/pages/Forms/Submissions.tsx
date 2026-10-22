import { Link, router } from '@inertiajs/react';
import AdminLayout from '../../components/AdminLayout';
import AppLayout from '../../components/AppLayout';
import FormStatusBadge from '../../components/forms/FormStatusBadge';
import EmptyState from '../../components/ui/EmptyState';
import Panel from '../../components/ui/Panel';
import StatTile from '../../components/ui/StatTile';
import type { FormAnswer, FormSummary } from '../../types';

interface SubmissionColumn {
    key: string;
    label: string;
    type: string;
    removed: boolean;
}

interface SubmissionRow {
    id: number;
    submitted_at: string | null;
    user: { name: string; email: string | null } | null;
    data: Record<string, FormAnswer | number>;
}

interface SubmissionsProps {
    form: FormSummary;
    columns: SubmissionColumn[];
    submissions: {
        data: SubmissionRow[];
        current_page: number;
        last_page: number;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    builderUrl: string;
    backUrl: string;
}

function displayAnswer(value: FormAnswer | number | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    return Array.isArray(value) ? value.join(', ') : String(value);
}

export default function Submissions({ form, columns, submissions, builderUrl, backUrl }: SubmissionsProps) {
    const Layout = form.team === null ? AdminLayout : AppLayout;
    const remaining = form.max_submissions === null ? null : Math.max(form.max_submissions - (form.submissions_count ?? 0), 0);

    function deleteSubmission(submission: SubmissionRow) {
        if (window.confirm('Delete this response? This cannot be undone.')) {
            router.delete(`/forms/${form.id}/responses/${submission.id}`, { preserveScroll: true });
        }
    }

    return (
        <Layout title={`Responses: ${form.title}`}>
            <div className="flex flex-wrap items-center gap-3">
                <Link href={backUrl} className="text-sm text-slate-400 hover:text-slate-200">← All forms</Link>
                <h1 className="text-2xl font-bold">{form.title}</h1>
                <FormStatusBadge status={form.status} />
                <div className="ml-auto flex flex-wrap gap-4 text-sm">
                    <Link href={builderUrl} className="text-slate-300 hover:text-emerald-300">Edit form</Link>
                    {submissions.total > 0 && (
                        <a href={`/forms/${form.id}/responses/export`} className="font-semibold text-emerald-400 hover:text-emerald-300">Download CSV</a>
                    )}
                </div>
            </div>

            <dl className="grid gap-3 sm:grid-cols-3">
                <StatTile label="Responses" value={submissions.total} />
                {remaining !== null && <StatTile label="Places left" value={remaining} hint={`Limit ${form.max_submissions}`} />}
                {form.closes_at && <StatTile label="Closes" value={new Date(form.closes_at).toLocaleDateString()} hint={new Date(form.closes_at).toLocaleTimeString()} />}
            </dl>

            <Panel title="Responses" description="Newest first. Download the CSV to open everything in a spreadsheet.">
                {submissions.data.length === 0 ? (
                    <EmptyState title="No responses yet" description={form.status === 'published' ? 'Share the form link to start collecting responses.' : 'Publish the form to start collecting responses.'} />
                ) : (
                    <>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-max text-left text-sm">
                                <thead className="border-b border-slate-800 text-xs uppercase tracking-wide text-slate-400">
                                    <tr>
                                        <th scope="col" className="px-3 py-2 font-medium">Submitted</th>
                                        <th scope="col" className="px-3 py-2 font-medium">Account</th>
                                        {columns.map((column) => (
                                            <th key={column.key} scope="col" className="max-w-56 px-3 py-2 font-medium">
                                                {column.label}{column.removed && <span className="normal-case text-slate-500"> (removed)</span>}
                                            </th>
                                        ))}
                                        <th scope="col" className="px-3 py-2"><span className="sr-only">Actions</span></th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-800">
                                    {submissions.data.map((submission) => (
                                        <tr key={submission.id} className="align-top">
                                            <td className="whitespace-nowrap px-3 py-2 text-slate-300">
                                                {submission.submitted_at ? new Date(submission.submitted_at).toLocaleString() : '—'}
                                            </td>
                                            <td className="px-3 py-2 text-slate-300">{submission.user?.email ?? submission.user?.name ?? '—'}</td>
                                            {columns.map((column) => (
                                                <td key={column.key} className="max-w-56 whitespace-pre-line break-words px-3 py-2 text-slate-100">
                                                    {displayAnswer(submission.data[column.key])}
                                                </td>
                                            ))}
                                            <td className="px-3 py-2 text-right">
                                                <button type="button" onClick={() => deleteSubmission(submission)} className="text-rose-300 hover:text-rose-200">
                                                    Delete<span className="sr-only"> response from {submission.submitted_at}</span>
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {submissions.last_page > 1 && (
                            <nav aria-label="Pages" className="flex items-center justify-between text-sm">
                                {submissions.prev_page_url ? <Link href={submissions.prev_page_url} preserveScroll className="text-slate-300 hover:text-emerald-300">← Newer</Link> : <span />}
                                <span className="text-slate-400">Page {submissions.current_page} of {submissions.last_page}</span>
                                {submissions.next_page_url ? <Link href={submissions.next_page_url} preserveScroll className="text-slate-300 hover:text-emerald-300">Older →</Link> : <span />}
                            </nav>
                        )}
                    </>
                )}
            </Panel>
        </Layout>
    );
}
