import type { ReactNode } from 'react';

/**
 * Placeholder for a list or section with nothing in it yet. Pass a button or link as `action` to show the next step.
 */
interface EmptyStateProps {
    title: string;
    description?: string;
    action?: ReactNode;
}

export default function EmptyState({ title, description, action }: EmptyStateProps) {
    return (
        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-slate-700 px-6 py-10 text-center">
            <p className="font-medium text-slate-200">{title}</p>
            {description && <p className="max-w-md text-sm text-slate-400">{description}</p>}
            {action && <div className="mt-2">{action}</div>}
        </div>
    );
}
