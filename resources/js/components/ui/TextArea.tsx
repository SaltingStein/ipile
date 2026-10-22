import type { TextareaHTMLAttributes } from 'react';

/**
 * Labelled multi-line input with its validation message; the textarea counterpart of TextField.
 */
interface TextAreaProps extends Omit<TextareaHTMLAttributes<HTMLTextAreaElement>, 'onChange' | 'value'> {
    label: string;
    name: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    hint?: string;
}

export default function TextArea({ label, name, value, onChange, error, hint, rows = 3, ...textareaProps }: TextAreaProps) {
    const describedBy = error ? `${name}-error` : hint ? `${name}-hint` : undefined;

    return (
        <div className="flex flex-col gap-1.5">
            <label htmlFor={name} className="text-sm font-medium text-slate-200">
                {label}
            </label>
            <textarea
                id={name}
                name={name}
                rows={rows}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy}
                className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 placeholder:text-slate-500 focus:border-emerald-500 focus:outline-none aria-invalid:border-rose-500"
                {...textareaProps}
            />
            {hint && !error && (
                <p id={`${name}-hint`} className="text-xs text-slate-400">
                    {hint}
                </p>
            )}
            {error && (
                <p id={`${name}-error`} className="text-sm text-rose-400">
                    {error}
                </p>
            )}
        </div>
    );
}
