import type { CampaignTemplateValue } from './types';

/**
 * A small drawing of each template's layout, for the template choices.
 */
export default function TemplateSketch({ template }: { template: CampaignTemplateValue }) {
    const bar = 'rounded-full bg-slate-600';

    if (template === 'split') {
        return (
            <div aria-hidden="true" className="grid h-24 grid-cols-2 gap-2 rounded-lg bg-slate-950 p-3">
                <div className="flex flex-col justify-center gap-1.5">
                    <span className={`h-2 w-4/5 ${bar}`} />
                    <span className={`h-1.5 w-3/5 ${bar} opacity-60`} />
                    <span className="mt-1 h-2.5 w-1/3 rounded-full bg-emerald-500/70" />
                </div>
                <div className="flex flex-col gap-1 rounded-md border border-slate-700 p-1.5">
                    <span className="h-2 rounded bg-slate-800" />
                    <span className="h-2 rounded bg-slate-800" />
                    <span className="mt-auto h-2 w-1/2 rounded bg-emerald-500/70" />
                </div>
            </div>
        );
    }

    if (template === 'story') {
        return (
            <div aria-hidden="true" className="flex h-24 flex-col gap-1.5 rounded-lg bg-slate-950 px-8 py-3">
                <span className={`h-2 w-3/5 ${bar}`} />
                <span className="h-8 rounded bg-gradient-to-br from-slate-700 to-slate-800" />
                <span className={`h-1 w-full ${bar} opacity-50`} />
                <span className={`h-1 w-11/12 ${bar} opacity-50`} />
                <span className={`h-1 w-4/5 ${bar} opacity-50`} />
            </div>
        );
    }

    return (
        <div aria-hidden="true" className="flex h-24 flex-col items-center justify-center gap-1.5 rounded-lg bg-gradient-to-br from-emerald-900/70 via-slate-800 to-slate-950 p-3">
            <span className="h-1.5 w-1/5 rounded-full bg-emerald-400/70" />
            <span className="h-2.5 w-3/5 rounded-full bg-slate-200/80" />
            <span className="h-1.5 w-2/5 rounded-full bg-slate-300/50" />
            <span className="mt-1 flex gap-1"><span className="h-2.5 w-8 rounded-full bg-emerald-500/80" /><span className="h-2.5 w-8 rounded-full border border-slate-400/60" /></span>
        </div>
    );
}
