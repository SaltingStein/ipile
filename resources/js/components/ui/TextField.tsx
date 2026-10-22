import type { InputHTMLAttributes } from 'react';

/**
 * Labelled input with its validation message, styled for the dark application shell.
 */
interface TextFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'onChange' | 'value'> {
    label: string;
    name: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    hint?: string;
}

export default function TextField({ label, name, value, onChange, error, hint, type = 'text', ...inputProps }: TextFieldProps) {
    const describedBy = error ? `${name}-error` : hint ? `${name}-hint` : undefined;

    return (
        <div className="flex flex-col gap-1.5">
            <label htmlFor={name} className="text-sm font-medium text-slate-200">
                {label}
            </label>
            <input
                id={name}
                name={name}
                type={type}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy}
                className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 placeholder:text-slate-500 focus:border-emerald-500 focus:outline-none aria-invalid:border-rose-500"
                {...inputProps}
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
