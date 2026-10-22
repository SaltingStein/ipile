import { useRef, useState } from 'react';
import type { ReactNode } from 'react';
import TextField from '../../ui/TextField';
import { parseVideoUrl } from '../../media/videoEmbed';
import type { CampaignButton } from '../types';

/**
 * Validation messages from the last save, keyed by field path (e.g. `sections.2.items.0.title`).
 */
export type BuilderErrors = Record<string, string | undefined>;

/**
 * Uploads a picture and returns its saved reference and address.
 */
export type ImageUploader = (file: File) => Promise<{ reference: string; url: string }>;

/**
 * Blank text is stored as null.
 */
export function orNull(value: string): string | null {
    return value === '' ? null : value;
}

export function ButtonsEditor({ idPrefix, path, buttons, min, errors, onChange }: {
    idPrefix: string;
    path: string;
    buttons: CampaignButton[];
    min: number;
    errors: BuilderErrors;
    onChange: (buttons: CampaignButton[]) => void;
}) {
    const update = (index: number, patch: Partial<CampaignButton>) => onChange(buttons.map((button, buttonIndex) => (buttonIndex === index ? { ...button, ...patch } : button)));

    return (
        <fieldset className="flex flex-col gap-3">
            <legend className="mb-1 text-sm font-medium text-slate-200">Buttons</legend>
            {errors[path] && <p className="text-sm text-rose-400">{errors[path]}</p>}
            {buttons.map((button, index) => (
                <div key={index} className="flex flex-col gap-2 rounded-lg border border-slate-800 p-3">
                    <div className="grid gap-2 sm:grid-cols-2">
                        <TextField label="Label" name={`${idPrefix}-button-${index}-label`} value={button.label} maxLength={40}
                            onChange={(label) => update(index, { label })} error={errors[`${path}.${index}.label`]} />
                        <TextField label="Link" name={`${idPrefix}-button-${index}-url`} value={button.url} maxLength={500} placeholder="https://… or #signup"
                            onChange={(url) => update(index, { url })} error={errors[`${path}.${index}.url`]} />
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <label htmlFor={`${idPrefix}-button-${index}-style`} className="flex items-center gap-2 text-slate-300">
                            Style
                            <select id={`${idPrefix}-button-${index}-style`} value={button.style} onChange={(event) => update(index, { style: event.target.value as CampaignButton['style'] })}
                                className="rounded-md border border-slate-700 bg-slate-950 px-2 py-1 text-slate-100">
                                <option value="primary">Filled (main action)</option>
                                <option value="secondary">Outline</option>
                            </select>
                        </label>
                        {buttons.length > min && (
                            <button type="button" onClick={() => onChange(buttons.filter((_, buttonIndex) => buttonIndex !== index))} className="text-rose-300 hover:text-rose-200">Remove button</button>
                        )}
                    </div>
                </div>
            ))}
            {buttons.length < 2 && (
                <button type="button" onClick={() => onChange([...buttons, { label: 'Learn more', url: '#details', style: buttons.length === 0 ? 'primary' : 'secondary' }])}
                    className="self-start rounded-lg border border-dashed border-slate-600 px-3 py-1.5 text-sm text-slate-300 hover:border-slate-400">
                    + Add button
                </button>
            )}
            <p className="text-xs text-slate-400">Use #signup to jump to the form, a page on this site such as /blog, or a full web address.</p>
        </fieldset>
    );
}

/**
 * A list of small items (features, questions, figures) with add, remove and reorder.
 */
export function ItemsEditor<T>({ label, path, items, max, errors, blank, onChange, children }: {
    label: string;
    path: string;
    items: T[];
    max: number;
    errors: BuilderErrors;
    blank: T;
    onChange: (items: T[]) => void;
    children: (item: T, index: number, update: (patch: Partial<T>) => void) => ReactNode;
}) {
    const move = (index: number, offset: number) => {
        const next = [...items];
        [next[index], next[index + offset]] = [next[index + offset], next[index]];
        onChange(next);
    };

    return (
        <fieldset className="flex flex-col gap-3">
            <legend className="mb-1 text-sm font-medium text-slate-200">{label}</legend>
            {errors[path] && <p className="text-sm text-rose-400">{errors[path]}</p>}
            {items.map((item, index) => (
                <div key={index} className="flex flex-col gap-2 rounded-lg border border-slate-800 p-3">
                    {children(item, index, (patch) => onChange(items.map((current, itemIndex) => (itemIndex === index ? { ...current, ...patch } : current))))}
                    <div className="flex justify-end gap-3 text-xs">
                        <button type="button" disabled={index === 0} onClick={() => move(index, -1)} className="text-slate-300 hover:text-slate-100 disabled:opacity-30">Move up</button>
                        <button type="button" disabled={index === items.length - 1} onClick={() => move(index, 1)} className="text-slate-300 hover:text-slate-100 disabled:opacity-30">Move down</button>
                        {items.length > 1 && <button type="button" onClick={() => onChange(items.filter((_, itemIndex) => itemIndex !== index))} className="text-rose-300 hover:text-rose-200">Remove</button>}
                    </div>
                </div>
            ))}
            {items.length < max && (
                <button type="button" onClick={() => onChange([...items, structuredClone(blank)])}
                    className="self-start rounded-lg border border-dashed border-slate-600 px-3 py-1.5 text-sm text-slate-300 hover:border-slate-400">
                    + Add
                </button>
            )}
        </fieldset>
    );
}

/**
 * Choose and upload a picture. Shows the current one with a button to take it off.
 */
export function ImagePicker({ id, label, imageUrl, limitKb, error, onUpload, onChange }: {
    id: string;
    label: string;
    imageUrl: string | null;
    limitKb: number;
    error?: string;
    onUpload: ImageUploader;
    onChange: (image: { reference: string; url: string } | null) => void;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);
    const [uploadError, setUploadError] = useState<string | null>(null);
    const limitMb = Math.round((limitKb / 1024) * 10) / 10;

    async function upload(file: File | undefined) {
        if (!file) {
            return;
        }

        if (file.size > limitKb * 1024) {
            setUploadError(`“${file.name}” is larger than ${limitMb} MB. Choose a smaller picture.`);

            return;
        }

        setUploading(true);
        setUploadError(null);

        try {
            onChange(await onUpload(file));
        } catch (exception) {
            setUploadError(exception instanceof Error ? exception.message : 'The picture could not be uploaded.');
        } finally {
            setUploading(false);
        }
    }

    return (
        <div className="flex flex-col gap-2">
            <span id={`${id}-label`} className="text-sm font-medium text-slate-200">{label}</span>
            {imageUrl && <img src={imageUrl} alt="" className="max-h-40 w-full rounded-lg object-cover" />}
            <div className="flex flex-wrap gap-2">
                <button type="button" aria-describedby={`${id}-label`} disabled={uploading} onClick={() => input.current?.click()}
                    className="rounded-lg border border-slate-700 px-3 py-1.5 text-sm text-slate-100 hover:border-slate-500 disabled:opacity-50">
                    {uploading ? 'Uploading…' : imageUrl ? 'Replace picture' : 'Upload picture'}
                </button>
                {imageUrl && <button type="button" onClick={() => onChange(null)} className="px-2 text-sm text-rose-300 hover:text-rose-200">Remove</button>}
            </div>
            <input ref={input} id={id} type="file" accept="image/jpeg,image/png,image/webp,image/gif" hidden
                onChange={(event) => {
                    void upload(event.target.files?.[0]);
                    event.target.value = '';
                }} />
            <p className="text-xs text-slate-400">JPG, PNG, WebP or GIF up to {limitMb} MB.</p>
            {(uploadError || error) && <p role="alert" className="text-sm text-rose-400">{uploadError ?? error}</p>}
        </div>
    );
}

export function VideoField({ id, value, error, onChange }: { id: string; value: string | null; error?: string; onChange: (url: string | null) => void }) {
    const invalid = Boolean(value) && parseVideoUrl(value) === null;

    return (
        <TextField label="YouTube or Vimeo link" name={id} value={value ?? ''} maxLength={500} placeholder="https://www.youtube.com/watch?v=…"
            hint="Paste the link from the address bar or the Share button. It plays without leaving the page."
            onChange={(url) => onChange(orNull(url))}
            error={error ?? (invalid ? 'This does not look like a YouTube or Vimeo video link.' : undefined)} />
    );
}
