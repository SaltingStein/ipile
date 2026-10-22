import type { FormFieldTypeOption, FormFieldTypeValue } from '../../../types';

/**
 * The field types. Drag one onto the form, or click it to add it below the selected field.
 */
interface FieldPaletteProps {
    fieldTypes: FormFieldTypeOption[];
    onAdd: (type: FormFieldTypeValue) => void;
    onDragStart: (type: FormFieldTypeValue) => void;
    onDragEnd: () => void;
    disabled?: boolean;
}

export default function FieldPalette({ fieldTypes, onAdd, onDragStart, onDragEnd, disabled = false }: FieldPaletteProps) {
    return (
        <section aria-labelledby="field-palette-title" className="flex flex-col gap-3 rounded-2xl border border-slate-800 bg-slate-900/80 p-4">
            <div>
                <h2 id="field-palette-title" className="font-semibold">Add a field</h2>
                <p className="text-xs text-slate-400">Drag onto the form, or click to add.</p>
            </div>
            <ul className="grid grid-cols-2 gap-2 lg:grid-cols-1">
                {fieldTypes.map((type) => (
                    <li key={type.value}>
                        <button
                            type="button"
                            draggable={!disabled}
                            disabled={disabled}
                            title={type.description}
                            onClick={() => onAdd(type.value)}
                            onDragStart={(event) => {
                                event.dataTransfer.effectAllowed = 'copy';
                                event.dataTransfer.setData('text/plain', type.label);
                                onDragStart(type.value);
                            }}
                            onDragEnd={onDragEnd}
                            className="flex w-full cursor-grab items-center gap-2 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-left text-sm text-slate-200 transition hover:border-emerald-500 hover:text-emerald-200 focus-visible:outline-2 focus-visible:outline-emerald-400 active:cursor-grabbing disabled:opacity-60"
                        >
                            <span aria-hidden="true" className="text-slate-500">⠿</span>
                            <span>{type.label}</span>
                        </button>
                    </li>
                ))}
            </ul>
        </section>
    );
}
