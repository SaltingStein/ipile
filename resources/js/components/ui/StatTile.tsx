/**
 * A headline number with a label. Place several inside a <dl className="grid …"> so screen readers pair each label with its value.
 */
interface StatTileProps {
    label: string;
    value: number | string;
    hint?: string;
}

export default function StatTile({ label, value, hint }: StatTileProps) {
    return (
        <div className="rounded-xl border border-slate-800 bg-slate-900/80 px-4 py-3">
            <dt className="text-xs uppercase tracking-wide text-slate-400">{label}</dt>
            <dd className="mt-1 text-2xl font-semibold tabular-nums text-slate-50">{typeof value === 'number' ? value.toLocaleString() : value}</dd>
            {hint && <dd className="mt-1 text-xs text-slate-400">{hint}</dd>}
        </div>
    );
}
