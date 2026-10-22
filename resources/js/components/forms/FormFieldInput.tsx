import type { FormAnswer, FormFieldDefinition } from '../../types';

/**
 * One form-builder field as people fill it in. Used on public forms, the sign-up page and the builder preview.
 */
interface FormFieldInputProps {
    field: FormFieldDefinition;
    value: FormAnswer | undefined;
    onChange: (value: FormAnswer) => void;
    error?: string;
    idPrefix?: string;
    disabled?: boolean;
}

const inputTypes: Partial<Record<FormFieldDefinition['type'], string>> = {
    text: 'text',
    email: 'email',
    phone: 'tel',
    number: 'number',
    date: 'date',
    url: 'url',
};

const autoCompletes: Partial<Record<FormFieldDefinition['type'], string>> = {
    email: 'email',
    phone: 'tel',
    url: 'url',
};

const controlClasses = 'w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 placeholder:text-slate-500 focus:border-emerald-500 focus:outline-none disabled:opacity-70 aria-invalid:border-rose-500';

export default function FormFieldInput({ field, value, onChange, error, idPrefix = 'field', disabled = false }: FormFieldInputProps) {
    const id = `${idPrefix}-${field.key}`;
    const describedBy = error ? `${id}-error` : field.hint ? `${id}-hint` : undefined;
    const requiredMark = field.required && <span aria-hidden="true" className="text-rose-400"> *</span>;
    const hint = field.hint && !error && <p id={`${id}-hint`} className="text-xs text-slate-400">{field.hint}</p>;
    const errorMessage = error && <p id={`${id}-error`} className="text-sm text-rose-400">{error}</p>;
    const text = typeof value === 'string' ? value : '';

    if (field.type === 'heading') {
        return (
            <div className="flex flex-col gap-1 border-b border-slate-800 pb-2 pt-2">
                <h3 className="text-lg font-semibold text-slate-50">{field.label}</h3>
                {field.hint && <p className="text-sm text-slate-400">{field.hint}</p>}
            </div>
        );
    }

    if (field.type === 'checkbox') {
        return (
            <div className="flex flex-col gap-1.5">
                <label htmlFor={id} className="flex items-start gap-3 text-sm text-slate-200">
                    <input id={id} type="checkbox" checked={value === true} disabled={disabled}
                        required={field.required} aria-invalid={error ? true : undefined} aria-describedby={describedBy}
                        onChange={(event) => onChange(event.target.checked)}
                        className="mt-0.5 size-4 rounded border-slate-600 bg-slate-950 accent-emerald-500" />
                    <span>{field.label}{requiredMark}</span>
                </label>
                {hint}
                {errorMessage}
            </div>
        );
    }

    if (field.type === 'radio' || field.type === 'checkboxes') {
        const selected = Array.isArray(value) ? value : [];
        const toggle = (option: string, checked: boolean) => onChange(checked
            ? field.options.filter((candidate) => candidate === option || selected.includes(candidate))
            : selected.filter((candidate) => candidate !== option));

        return (
            <fieldset aria-describedby={describedBy} aria-invalid={error ? true : undefined} className="flex flex-col gap-2">
                <legend className="mb-1.5 text-sm font-medium text-slate-200">{field.label}{requiredMark}</legend>
                {field.options.map((option, index) => (
                    <label key={option} htmlFor={`${id}-${index}`} className="flex items-center gap-3 text-sm text-slate-200">
                        {field.type === 'radio' ? (
                            <input id={`${id}-${index}`} type="radio" name={id} value={option} checked={text === option} disabled={disabled}
                                onChange={() => onChange(option)} className="size-4 accent-emerald-500" />
                        ) : (
                            <input id={`${id}-${index}`} type="checkbox" value={option} checked={selected.includes(option)} disabled={disabled}
                                onChange={(event) => toggle(option, event.target.checked)} className="size-4 rounded accent-emerald-500" />
                        )}
                        <span>{option}</span>
                    </label>
                ))}
                {hint}
                {errorMessage}
            </fieldset>
        );
    }

    return (
        <div className="flex flex-col gap-1.5">
            <label htmlFor={id} className="text-sm font-medium text-slate-200">{field.label}{requiredMark}</label>
            {field.type === 'textarea' ? (
                <textarea id={id} rows={4} value={text} disabled={disabled} required={field.required}
                    placeholder={field.placeholder ?? undefined} maxLength={field.max ?? 5000}
                    aria-invalid={error ? true : undefined} aria-describedby={describedBy}
                    onChange={(event) => onChange(event.target.value)} className={controlClasses} />
            ) : field.type === 'select' ? (
                <select id={id} value={text} disabled={disabled} required={field.required}
                    aria-invalid={error ? true : undefined} aria-describedby={describedBy}
                    onChange={(event) => onChange(event.target.value)} className={controlClasses}>
                    <option value="">Choose…</option>
                    {field.options.map((option) => <option key={option} value={option}>{option}</option>)}
                </select>
            ) : (
                <input id={id} type={inputTypes[field.type] ?? 'text'} value={text} disabled={disabled} required={field.required}
                    placeholder={field.placeholder ?? undefined} autoComplete={autoCompletes[field.type]}
                    min={field.type === 'number' ? field.min ?? undefined : undefined}
                    max={field.type === 'number' ? field.max ?? undefined : undefined}
                    step={field.type === 'number' ? 'any' : undefined}
                    maxLength={field.type === 'text' ? field.max ?? 255 : undefined}
                    aria-invalid={error ? true : undefined} aria-describedby={describedBy}
                    onChange={(event) => onChange(event.target.value)} className={controlClasses} />
            )}
            {hint}
            {errorMessage}
        </div>
    );
}
