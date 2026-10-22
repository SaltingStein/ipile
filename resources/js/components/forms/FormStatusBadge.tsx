import type { FormStatusValue } from '../../types';

const styles: Record<FormStatusValue, string> = {
    draft: 'border-slate-600 text-slate-300',
    published: 'border-emerald-700 bg-emerald-500/10 text-emerald-300',
    closed: 'border-amber-700 bg-amber-500/10 text-amber-300',
};

const labels: Record<FormStatusValue, string> = {
    draft: 'Draft',
    published: 'Published',
    closed: 'Closed',
};

export default function FormStatusBadge({ status }: { status: FormStatusValue }) {
    return <span className={`rounded-full border px-2 py-0.5 text-xs font-medium ${styles[status]}`}>{labels[status]}</span>;
}
