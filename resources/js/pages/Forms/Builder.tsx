import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import AdminLayout from '../../components/AdminLayout';
import AppLayout from '../../components/AppLayout';
import FormStatusBadge from '../../components/forms/FormStatusBadge';
import FieldCanvas from '../../components/forms/builder/FieldCanvas';
import FieldPalette from '../../components/forms/builder/FieldPalette';
import FieldSettings from '../../components/forms/builder/FieldSettings';
import FormSettingsPanel from '../../components/forms/builder/FormSettingsPanel';
import PageDesignSettings from '../../components/forms/builder/PageDesignSettings';
import ShareFormDialog from '../../components/forms/builder/ShareFormDialog';
import type { FormSettingsData } from '../../components/forms/builder/FormSettingsPanel';
import {
    createField, duplicateField, fromLocalDateTimeInput, moveItem, toFieldKey, toLocalDateTimeInput, uniqueKey,
} from '../../components/forms/builder/fieldUtils';
import type { DragPayload } from '../../components/forms/builder/fieldUtils';
import Button from '../../components/ui/Button';
import type { FormFieldDefinition, FormFieldTypeOption, FormFieldTypeValue, FormLayoutValue, FormStatusValue, FormSummary } from '../../types';

interface BuilderProps {
    form: FormSummary & { fields: FormFieldDefinition[] };
    fieldTypes: FormFieldTypeOption[];
    statuses: { value: FormStatusValue; label: string }[];
    layouts: { value: FormLayoutValue; label: string; description: string }[];
    mediaLimits: { image_kb: number; video_kb: number };
    menus: { path: string; site: boolean; workspace: boolean } | null;
    backUrl: string;
}

type BuilderData = FormSettingsData & { layout: FormLayoutValue; fields: FormFieldDefinition[] };

type SettingsTab = 'field' | 'form' | 'design';

const builtInSignupFields = ['Name', 'Email', 'Password', 'Confirm password'];

export default function Builder({ form, fieldTypes, statuses, layouts, mediaLimits, menus, backUrl }: BuilderProps) {
    const Layout = form.team === null ? AdminLayout : AppLayout;
    const editor = useForm<BuilderData>({
        title: form.title,
        description: form.description ?? '',
        slug: form.slug ?? '',
        status: form.status,
        submit_label: form.submit_label ?? '',
        success_message: form.success_message ?? '',
        max_submissions: form.max_submissions?.toString() ?? '',
        closes_at: toLocalDateTimeInput(form.closes_at),
        sends_confirmation: form.sends_confirmation,
        layout: form.layout,
        fields: form.fields,
    });
    const errors = editor.errors as Record<string, string | undefined>;
    const fields = editor.data.fields;
    const hasResponses = (form.submissions_count ?? 0) > 0;

    const [selectedId, setSelectedId] = useState<string | null>(form.fields[0]?.id ?? null);
    const [panel, setPanel] = useState<SettingsTab>(form.fields.length > 0 ? 'field' : 'form');
    const [shareOpen, setShareOpen] = useState(false);
    const canShare = form.public_url !== null && form.status === 'published';
    // New fields take their ID from the label until someone edits the ID by hand or the form is saved.
    const [autoKeyIds, setAutoKeyIds] = useState<string[]>([]);
    const [isDragging, setIsDragging] = useState(false);
    const [announcement, setAnnouncement] = useState('');
    const dragPayload = useRef<DragPayload | null>(null);

    const selectedIndex = fields.findIndex((field) => field.id === selectedId);
    const selectedField = selectedIndex >= 0 ? fields[selectedIndex] : null;
    const fieldsWithErrors = useMemo(() => new Set(Object.keys(errors)
        .map((key) => /^fields\.(\d+)\./.exec(key)?.[1])
        .filter((index): index is string => index !== undefined)
        .map(Number)), [errors]);

    useWarnAboutUnsavedChanges(editor.isDirty && !editor.processing);

    function setFields(next: FormFieldDefinition[]) {
        editor.setData('fields', next);
    }

    function addField(typeValue: FormFieldTypeValue, atIndex?: number) {
        const type = fieldTypes.find((candidate) => candidate.value === typeValue);

        if (!type) {
            return;
        }

        const field = createField(type, fields);
        const index = atIndex ?? (selectedIndex >= 0 ? selectedIndex + 1 : fields.length);
        setFields([...fields.slice(0, index), field, ...fields.slice(index)]);
        setAutoKeyIds((ids) => [...ids, field.id]);
        setSelectedId(field.id);
        setPanel('field');
        setAnnouncement(`${type.label} added at position ${index + 1}.`);
    }

    function updateField(id: string, changes: Partial<FormFieldDefinition>) {
        setFields(fields.map((field) => {
            if (field.id !== id) {
                return field;
            }

            const updated = { ...field, ...changes };

            if (changes.label !== undefined && autoKeyIds.includes(id)) {
                updated.key = uniqueKey(toFieldKey(changes.label || field.type), fields.filter((other) => other.id !== id).map((other) => other.key));
            }

            return updated;
        }));
    }

    function changeKey(id: string, key: string) {
        setAutoKeyIds((ids) => ids.filter((candidate) => candidate !== id));
        setFields(fields.map((field) => (field.id === id ? { ...field, key } : field)));
    }

    function moveField(index: number, direction: -1 | 1) {
        setFields(moveItem(fields, index, direction === -1 ? index - 1 : index + 2));
        setAnnouncement(`${fields[index].label} moved to position ${index + 1 + direction}.`);
    }

    function removeField(id: string) {
        const field = fields.find((candidate) => candidate.id === id);

        if (!field || (hasResponses && !window.confirm(`Remove “${field.label}”? Earlier answers are kept and shown as a removed column.`))) {
            return;
        }

        const index = fields.indexOf(field);
        const remaining = fields.filter((candidate) => candidate.id !== id);
        setFields(remaining);
        setSelectedId(remaining[Math.min(index, remaining.length - 1)]?.id ?? null);
        setAnnouncement(`${field.label} removed.`);
    }

    function copyField(id: string) {
        const index = fields.findIndex((field) => field.id === id);
        const copy = duplicateField(fields[index], fields);
        setFields([...fields.slice(0, index + 1), copy, ...fields.slice(index + 1)]);
        setSelectedId(copy.id);
        setAnnouncement(`${fields[index].label} duplicated.`);
    }

    function drop(dropIndex: number) {
        const payload = dragPayload.current;

        if (payload?.kind === 'new') {
            addField(payload.type, dropIndex);
        } else if (payload?.kind === 'move') {
            const fromIndex = fields.findIndex((field) => field.id === payload.id);
            setFields(moveItem(fields, fromIndex, dropIndex));
            setSelectedId(payload.id);
        }

        endDrag();
    }

    function startDrag(payload: DragPayload) {
        dragPayload.current = payload;
        setIsDragging(true);
    }

    function endDrag() {
        dragPayload.current = null;
        setIsDragging(false);
    }

    function save(event?: FormEvent) {
        event?.preventDefault();
        const isBeingPublished = form.status !== 'published' && editor.data.status === 'published' && !form.is_registration;
        editor.transform((data) => ({
            ...data,
            max_submissions: data.max_submissions === '' ? null : Number(data.max_submissions),
            closes_at: fromLocalDateTimeInput(data.closes_at),
            fields: data.fields.map((field) => ({ ...field, options: field.options.map((option) => option.trim()).filter(Boolean) })),
        }));
        editor.put(`/forms/${form.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                setAutoKeyIds([]);
                editor.setDefaults();

                if (isBeingPublished) {
                    setShareOpen(true);
                }
            },
            onError: (serverErrors) => {
                const firstFieldIndex = Object.keys(serverErrors).map((key) => /^fields\.(\d+)\./.exec(key)?.[1]).find((index) => index !== undefined);

                if (Object.keys(serverErrors).some((key) => !key.startsWith('fields'))) {
                    setPanel('form');
                } else if (firstFieldIndex !== undefined) {
                    setSelectedId(fields[Number(firstFieldIndex)]?.id ?? null);
                    setPanel('field');
                }
            },
        });
    }

    function deleteForm() {
        if (window.confirm(`Delete “${form.title}” and all ${form.submissions_count ?? 0} responses? This cannot be undone.`)) {
            router.delete(`/forms/${form.id}`);
        }
    }

    const lockedFields = form.is_registration && (
        <div className="flex flex-col gap-2 rounded-xl border border-dashed border-slate-700 p-4">
            <p className="text-xs uppercase tracking-wide text-slate-400">Always asked by the sign-up page</p>
            <ul className="flex flex-wrap gap-2">
                {builtInSignupFields.map((label) => (
                    <li key={label} className="rounded-full border border-slate-700 px-3 py-1 text-sm text-slate-300">🔒 {label}</li>
                ))}
            </ul>
            <p className="text-xs text-slate-400">Your questions below appear after these once the form is published.</p>
        </div>
    );

    const tabClasses = (tab: SettingsTab) => `flex-1 rounded-lg px-3 py-1.5 text-sm font-medium transition ${panel === tab ? 'bg-slate-800 text-slate-50' : 'text-slate-400 hover:text-slate-200'}`;

    return (
        <Layout title={form.is_registration ? 'Sign-up questions' : `Edit ${form.title}`}>
            <form onSubmit={save} className="flex flex-col gap-4">
                <div className="flex flex-wrap items-center gap-3">
                    <Link href={backUrl} className="text-sm text-slate-400 hover:text-slate-200">← All forms</Link>
                    <FormStatusBadge status={form.status} />
                    <div className="ml-auto flex flex-wrap items-center gap-3 text-sm">
                        {editor.isDirty && <span className="text-amber-300">Unsaved changes</span>}
                        {editor.recentlySuccessful && !editor.isDirty && <span className="text-emerald-300">Saved</span>}
                        <Link href={`/forms/${form.id}/responses`} className="text-slate-300 hover:text-slate-50">
                            Responses ({form.submissions_count ?? 0})
                        </Link>
                        {form.public_url && form.status !== 'draft' && (
                            <a href={form.public_url} target="_blank" rel="noreferrer" className="text-slate-300 hover:text-slate-50">Open form ↗</a>
                        )}
                        {canShare && <Button type="button" variant="secondary" onClick={() => setShareOpen(true)}>Share</Button>}
                        <Button disabled={editor.processing}>{editor.processing ? 'Saving…' : 'Save'}</Button>
                    </div>
                </div>

                <p aria-live="polite" className="sr-only">{announcement}</p>

                <div className="grid gap-4 lg:grid-cols-[11rem_minmax(0,1fr)_17rem] lg:items-start">
                    <div className="lg:sticky lg:top-4">
                        <FieldPalette fieldTypes={fieldTypes} disabled={editor.processing || fields.length >= 60}
                            onAdd={(type) => addField(type)} onDragStart={(type) => startDrag({ kind: 'new', type })} onDragEnd={endDrag} />
                    </div>

                    <FieldCanvas
                        title={editor.data.title}
                        description={form.is_registration ? '' : editor.data.description}
                        fields={fields}
                        fieldTypes={fieldTypes}
                        selectedId={selectedId}
                        isDragging={isDragging}
                        fieldsWithErrors={fieldsWithErrors}
                        fieldsError={errors.fields}
                        lockedFields={lockedFields}
                        onSelect={(id) => {
                            setSelectedId(id);
                            setPanel('field');
                        }}
                        onDrop={drop}
                        onFieldDragStart={(id) => startDrag({ kind: 'move', id })}
                        onDragEnd={endDrag}
                        onMove={moveField}
                        onDuplicate={copyField}
                        onRemove={removeField}
                    />

                    <aside aria-label="Settings" className="flex flex-col gap-4 rounded-2xl border border-slate-800 bg-slate-900/80 p-4 lg:sticky lg:top-4 lg:max-h-[calc(100vh-2rem)] lg:overflow-y-auto">
                        <div role="tablist" aria-label="Settings" className="flex gap-1 rounded-xl border border-slate-800 p-1">
                            <button type="button" role="tab" aria-selected={panel === 'field'} onClick={() => setPanel('field')} className={tabClasses('field')}>Field</button>
                            <button type="button" role="tab" aria-selected={panel === 'form'} onClick={() => setPanel('form')} className={tabClasses('form')}>
                                Form{Object.keys(errors).some((key) => !key.startsWith('fields')) && <span className="text-rose-400"> •</span>}
                            </button>
                            {!form.is_registration && (
                                <button type="button" role="tab" aria-selected={panel === 'design'} onClick={() => setPanel('design')} className={tabClasses('design')}>Design</button>
                            )}
                        </div>
                        <div role="tabpanel">
                            {panel === 'field' ? (
                                selectedField ? (
                                    <FieldSettings
                                        key={selectedField.id}
                                        field={selectedField}
                                        index={selectedIndex}
                                        fieldType={fieldTypes.find((type) => type.value === selectedField.type)}
                                        errors={errors}
                                        hasResponses={hasResponses}
                                        onChange={(changes) => updateField(selectedField.id, changes)}
                                        onKeyChange={(key) => changeKey(selectedField.id, key)}
                                    />
                                ) : (
                                    <p className="text-sm text-slate-400">Select a field on the form to change it, or add one from “Add a field”.</p>
                                )
                            ) : panel === 'design' ? (
                                <PageDesignSettings
                                    formId={form.id}
                                    layout={editor.data.layout}
                                    layouts={layouts}
                                    media={form.media}
                                    limits={mediaLimits}
                                    onLayoutChange={(layout) => editor.setData('layout', layout)}
                                />
                            ) : (
                                <FormSettingsPanel
                                    data={editor.data}
                                    errors={errors}
                                    statuses={statuses}
                                    isRegistration={form.is_registration}
                                    onChange={(key, value) => editor.setData(key, value as never)}
                                    onDelete={form.is_registration ? undefined : deleteForm}
                                />
                            )}
                        </div>
                    </aside>
                </div>
            </form>
            {form.public_url && (
                <ShareFormDialog open={shareOpen} onClose={() => setShareOpen(false)} title={form.title} url={form.public_url} menus={menus} />
            )}
        </Layout>
    );
}

/**
 * Ask before leaving the builder (in-app link or closing the tab) with unsaved changes.
 */
function useWarnAboutUnsavedChanges(hasUnsavedChanges: boolean) {
    useEffect(() => {
        if (!hasUnsavedChanges) {
            return;
        }

        const message = 'You have unsaved changes. Leave without saving?';
        const beforeUnload = (event: BeforeUnloadEvent) => event.preventDefault();
        const removeInertiaListener = router.on('before', (event) => {
            if (event.detail.visit.method === 'get' && !window.confirm(message)) {
                event.preventDefault();
            }
        });
        window.addEventListener('beforeunload', beforeUnload);

        return () => {
            removeInertiaListener();
            window.removeEventListener('beforeunload', beforeUnload);
        };
    }, [hasUnsavedChanges]);
}
