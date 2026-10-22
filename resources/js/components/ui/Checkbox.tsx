/**
 * Labelled checkbox used for "remember me", "trust this device" and dashboard toggles.
 */
interface CheckboxProps {
    label: string;
    name: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    description?: string;
    disabled?: boolean;
}

export default function Checkbox({ label, name, checked, onChange, description, disabled = false }: CheckboxProps) {
    return (
        <label htmlFor={name} className="flex items-start gap-3 text-sm text-slate-200">
            <input
                id={name}
                name={name}
                type="checkbox"
                checked={checked}
                disabled={disabled}
                onChange={(event) => onChange(event.target.checked)}
                className="mt-0.5 size-4 rounded border-slate-600 bg-slate-950 accent-emerald-500 disabled:opacity-60"
            />
            <span className="flex flex-col gap-0.5">
                <span>{label}</span>
                {description && <span className="text-xs text-slate-400">{description}</span>}
            </span>
        </label>
    );
}
