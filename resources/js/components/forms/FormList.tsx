import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import EmptyState from '../ui/EmptyState';
import StatusMessage from '../ui/StatusMessage';
import FormStatusBadge from './FormStatusBadge';
import type { FormSummary } from '../../types';

/**
 * Forms with their status, response count and links. Without `canManage` only the public link is shown.
 */
interface FormListProps {
    forms: FormSummary[];
    canManage: boolean;
    emptyDescription: string;
}

export default function FormList({ forms, canManage, emptyDescription }: FormListProps) {
    const [copiedId, setCopiedId] = useState<number | null>(null);
    const [duplicatingId, setDuplicatingId] = useState<number | null>(null);
    const [duplicateError, setDuplicateError] = useState<string | null>(null);

    function duplicate(form: FormSummary) {
        setDuplicateError(null);
        router.post(`/forms/${form.id}/duplicate`, {}, {
            onStart: () => setDuplicatingId(form.id),
            onError: (errors) => setDuplicateError(errors.duplicate ?? 'The form could not be copied. Please try again.'),
            onFinish: () => setDuplicatingId(null),
        });
    }

    async function copyLink(form: FormSummary) {
        if (form.public_url && navigator.clipboard) {
            await navigator.clipboard.writeText(form.public_url);
            setCopiedId(form.id);
        }
    }

    if (forms.length === 0) {
        return <EmptyState title="No forms yet" description={emptyDescription} />;
    }

    const linkClasses = 'text-slate-300 hover:text-emerald-300';

    return (
        <>
            <StatusMessage message={duplicateError} tone="error" />
            <ul className="flex flex-col divide-y divide-slate-800">
                {forms.map((form) => (
                    <li key={form.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div className="flex min-w-0 flex-col gap-1">
                            <div className="flex flex-wrap items-center gap-2">
                                {canManage ? (
                                    <Link href={`/forms/${form.id}/edit`} className="font-medium text-slate-100 hover:text-emerald-300">{form.title}</Link>
                                ) : (
                                    <span className="font-medium text-slate-100">{form.title}</span>
                                )}
                                <FormStatusBadge status={form.status} />
                                {form.is_registration && <span className="rounded-full border border-sky-700 bg-sky-500/10 px-2 py-0.5 text-xs text-sky-300">Sign-up page</span>}
                            </div>
                            <p className="text-xs text-slate-400">
                                {form.submissions_count ?? 0}{form.max_submissions ? ` of ${form.max_submissions}` : ''} responses
                                {form.closes_at && ` · closes ${new Date(form.closes_at).toLocaleString()}`}
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-4 text-sm">
                            {form.public_url && form.status !== 'draft' && (
                                <>
                                    <a href={form.public_url} target="_blank" rel="noreferrer" className={linkClasses}>Open ↗</a>
                                    <button type="button" onClick={() => copyLink(form)} className={linkClasses}>
                                        {copiedId === form.id ? 'Link copied' : 'Copy link'}
                                    </button>
                                </>
                            )}
                            {canManage && (
                                <>
                                    <Link href={`/forms/${form.id}/responses`} className={linkClasses}>Responses</Link>
                                    <Link href={`/forms/${form.id}/edit`} className={linkClasses}>Edit</Link>
                                    <button type="button" onClick={() => duplicate(form)} disabled={duplicatingId !== null}
                                        aria-label={`Copy ${form.title} as draft`}
                                        className={`${linkClasses} disabled:cursor-wait disabled:opacity-50`}>
                                        {duplicatingId === form.id ? 'Copying…' : 'Copy as draft'}
                                    </button>
                                </>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
        </>
    );
}
