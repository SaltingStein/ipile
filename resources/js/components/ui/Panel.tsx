import type { ReactNode } from 'react';

/**
 * A titled card that groups one settings topic.
 */
interface PanelProps {
    title: string;
    description?: string;
    children: ReactNode;
}

export default function Panel({ title, description, children }: PanelProps) {
    return (
        <section className="flex flex-col gap-5 rounded-2xl border border-slate-800 bg-slate-900/80 p-6">
            <div className="flex flex-col gap-1">
                <h2 className="text-lg font-semibold">{title}</h2>
                {description && <p className="text-sm text-slate-400">{description}</p>}
            </div>
            {children}
        </section>
    );
}
