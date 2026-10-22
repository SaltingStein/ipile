import { useState } from 'react';
import type { DragEvent, ReactNode } from 'react';
import FormFieldInput from '../FormFieldInput';
import type { FormFieldDefinition, FormFieldTypeOption } from '../../../types';

/**
 * The form as people will see it. Fields can be dragged to reorder, and palette items dropped between them.
 * Every drag action also has a button (move up/down), so the builder works on touch screens and with a keyboard.
 */
interface FieldCanvasProps {
    title: string;
    description: string;
    fields: FormFieldDefinition[];
    fieldTypes: FormFieldTypeOption[];
    selectedId: string | null;
    isDragging: boolean;
    fieldsWithErrors: Set<number>;
    fieldsError?: string;
    lockedFields?: ReactNode;
    onSelect: (id: string) => void;
    onDrop: (dropIndex: number) => void;
    onFieldDragStart: (id: string) => void;
    onDragEnd: () => void;
    onMove: (index: number, direction: -1 | 1) => void;
    onDuplicate: (id: string) => void;
    onRemove: (id: string) => void;
}

export default function FieldCanvas({
    title, description, fields, fieldTypes, selectedId, isDragging, fieldsWithErrors, fieldsError, lockedFields,
    onSelect, onDrop, onFieldDragStart, onDragEnd, onMove, onDuplicate, onRemove,
}: FieldCanvasProps) {
    const [dropIndex, setDropIndex] = useState<number | null>(null);
    const typeLabel = (field: FormFieldDefinition) => fieldTypes.find((type) => type.value === field.type)?.label ?? field.type;

    function overField(event: DragEvent<HTMLLIElement>, index: number) {
        if (!isDragging) {
            return;
        }

        event.preventDefault();
        const bounds = event.currentTarget.getBoundingClientRect();
        setDropIndex(event.clientY < bounds.top + bounds.height / 2 ? index : index + 1);
    }

    function drop(event: DragEvent<HTMLElement>) {
        if (!isDragging) {
            return;
        }

        event.preventDefault();
        onDrop(dropIndex ?? fields.length);
        setDropIndex(null);
    }

    const indicator = (key: string) => <li key={key} aria-hidden="true" className="h-1 rounded-full bg-emerald-400" />;

    return (
        <section aria-labelledby="form-canvas-title" className="flex min-w-0 flex-col gap-4 rounded-2xl border border-slate-800 bg-slate-900/80 p-5">
            <header className="flex flex-col gap-1 border-b border-slate-800 pb-4">
                <h2 id="form-canvas-title" className="text-xl font-semibold text-slate-50">{title || 'Untitled form'}</h2>
                {description && <p className="whitespace-pre-line text-sm text-slate-400">{description}</p>}
            </header>

            {lockedFields}

            {fieldsError && <p role="alert" className="text-sm text-rose-400">{fieldsError}</p>}

            <ol
                aria-label="Form fields"
                className="flex min-h-40 flex-col gap-3"
                onDragOver={(event) => {
                    if (isDragging) {
                        event.preventDefault();
                    }
                }}
                onDragLeave={(event) => {
                    if (!event.currentTarget.contains(event.relatedTarget as Node | null)) {
                        setDropIndex(null);
                    }
                }}
                onDrop={drop}
            >
                {fields.length === 0 && (
                    <li
                        onDragOver={(event) => {
                            if (isDragging) {
                                event.preventDefault();
                                setDropIndex(0);
                            }
                        }}
                        className={`flex flex-1 items-center justify-center rounded-xl border-2 border-dashed p-10 text-center text-sm ${dropIndex === 0 ? 'border-emerald-400 text-emerald-200' : 'border-slate-700 text-slate-400'}`}
                    >
                        Drag a field here, or click one in “Add a field”.
                    </li>
                )}
                {fields.map((field, index) => {
                    const isSelected = field.id === selectedId;
                    const hasError = fieldsWithErrors.has(index);

                    return [
                        dropIndex === index && indicator(`${field.id}-drop`),
                        <li
                            key={field.id}
                            draggable
                            onDragStart={(event) => {
                                event.dataTransfer.effectAllowed = 'move';
                                event.dataTransfer.setData('text/plain', field.label);
                                onFieldDragStart(field.id);
                            }}
                            onDragEnd={() => {
                                setDropIndex(null);
                                onDragEnd();
                            }}
                            onDragOver={(event) => overField(event, index)}
                            onClick={() => onSelect(field.id)}
                            className={`group flex cursor-pointer flex-col gap-3 rounded-xl border bg-slate-950/60 p-4 transition ${isSelected ? 'border-emerald-500 ring-1 ring-emerald-500/50' : hasError ? 'border-rose-500' : 'border-slate-800 hover:border-slate-600'}`}
                        >
                            <div className="flex flex-wrap items-center gap-2 text-xs">
                                <span aria-hidden="true" className="cursor-grab text-base text-slate-500 active:cursor-grabbing" title="Drag to reorder">⠿</span>
                                <button type="button" onClick={(event) => { event.stopPropagation(); onSelect(field.id); }}
                                    aria-pressed={isSelected}
                                    className="rounded-full border border-slate-700 px-2 py-0.5 text-slate-300 hover:border-emerald-500 focus-visible:outline-2 focus-visible:outline-emerald-400">
                                    {typeLabel(field)} · Edit<span className="sr-only"> {field.label}</span>
                                </button>
                                {field.required && <span className="rounded-full bg-rose-500/10 px-2 py-0.5 text-rose-300">Required</span>}
                                {hasError && <span className="text-rose-400">Needs attention</span>}
                                <span className="ml-auto flex gap-1 opacity-100 sm:opacity-60 sm:group-hover:opacity-100">
                                    <CardButton label={`Move ${field.label} up`} disabled={index === 0} onClick={() => onMove(index, -1)}>↑</CardButton>
                                    <CardButton label={`Move ${field.label} down`} disabled={index === fields.length - 1} onClick={() => onMove(index, 1)}>↓</CardButton>
                                    <CardButton label={`Duplicate ${field.label}`} onClick={() => onDuplicate(field.id)}>⧉</CardButton>
                                    <CardButton label={`Remove ${field.label}`} onClick={() => onRemove(field.id)} danger>✕</CardButton>
                                </span>
                            </div>
                            <div className="pointer-events-none">
                                <FormFieldInput field={field} value={null} onChange={() => {}} idPrefix={`preview-${field.id}`} disabled />
                            </div>
                        </li>,
                    ];
                })}
                {fields.length > 0 && dropIndex === fields.length && indicator('end-drop')}
            </ol>
        </section>
    );
}

interface CardButtonProps {
    label: string;
    onClick: () => void;
    disabled?: boolean;
    danger?: boolean;
    children: ReactNode;
}

function CardButton({ label, onClick, disabled = false, danger = false, children }: CardButtonProps) {
    return (
        <button type="button" aria-label={label} title={label} disabled={disabled}
            onClick={(event) => {
                event.stopPropagation();
                onClick();
            }}
            className={`flex size-7 items-center justify-center rounded-md border border-slate-700 text-sm transition focus-visible:outline-2 focus-visible:outline-emerald-400 disabled:opacity-30 ${danger ? 'text-rose-300 hover:border-rose-500' : 'text-slate-300 hover:border-slate-500'}`}>
            {children}
        </button>
    );
}
