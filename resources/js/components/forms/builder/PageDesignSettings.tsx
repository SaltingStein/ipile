import { useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import Button from '../../ui/Button';
import type { FormLayoutValue, FormMedia, SharedPageProps } from '../../../types';

/**
 * Choose the public page's layout and upload its banner image or video. Uploads are saved straight away;
 * the layout is saved with the rest of the form.
 */
interface PageDesignSettingsProps {
    formId: number;
    layout: FormLayoutValue;
    layouts: { value: FormLayoutValue; label: string; description: string }[];
    media: FormMedia | null;
    limits: { image_kb: number; video_kb: number };
    onLayoutChange: (layout: FormLayoutValue) => void;
}

const megabytes = (kilobytes: number) => `${Math.round((kilobytes / 1024) * 10) / 10} MB`;

export default function PageDesignSettings({ formId, layout, layouts, media, limits, onLayoutChange }: PageDesignSettingsProps) {
    const serverErrors = usePage<SharedPageProps>().props.errors as Record<string, string | undefined>;
    const fileInput = useRef<HTMLInputElement>(null);
    const [progress, setProgress] = useState<number | null>(null);
    const [clientError, setClientError] = useState<string | null>(null);
    const error = clientError ?? serverErrors.media;

    function upload(file: File) {
        const isVideo = file.type.startsWith('video/');
        const limitKb = isVideo ? limits.video_kb : limits.image_kb;
        setClientError(null);

        if (file.size > limitKb * 1024) {
            setClientError(`This ${isVideo ? 'video' : 'image'} is ${megabytes(file.size / 1024)}. The limit is ${megabytes(limitKb)}.`);

            return;
        }

        router.post(`/forms/${formId}/media`, { media: file }, {
            forceFormData: true,
            preserveScroll: true,
            onProgress: (event) => setProgress(event?.percentage ?? null),
            onFinish: () => {
                setProgress(null);

                if (fileInput.current) {
                    fileInput.current.value = '';
                }
            },
        });
    }

    function remove() {
        if (window.confirm('Remove the banner from this form?')) {
            router.delete(`/forms/${formId}/media`, { preserveScroll: true });
        }
    }

    return (
        <div className="flex flex-col gap-5">
            <fieldset className="flex flex-col gap-2">
                <legend className="mb-1 text-sm font-medium text-slate-200">Page layout</legend>
                {layouts.map((option) => (
                    <label key={option.value} htmlFor={`form-layout-${option.value}`}
                        className={`flex cursor-pointer gap-3 rounded-lg border p-3 text-sm ${layout === option.value ? 'border-emerald-500 bg-emerald-500/5' : 'border-slate-800'}`}>
                        <input id={`form-layout-${option.value}`} type="radio" name="form-layout" value={option.value}
                            checked={layout === option.value} onChange={() => onLayoutChange(option.value)} className="mt-0.5 size-4 accent-emerald-500" />
                        <span className="flex flex-1 flex-col gap-2">
                            <span className="font-medium text-slate-100">{option.label}</span>
                            <LayoutSketch layout={option.value} />
                            <span className="text-xs text-slate-400">{option.description}</span>
                        </span>
                    </label>
                ))}
                <p className="text-xs text-slate-400">Choose <strong>Save</strong> to apply the layout.</p>
            </fieldset>

            <div className="flex flex-col gap-2">
                <p className="text-sm font-medium text-slate-200">Banner image or video</p>
                {media?.type === 'image' && <img src={media.url} alt="Current banner" className="aspect-[3/1] w-full rounded-lg object-cover" />}
                {media?.type === 'video' && <video src={media.url} controls muted playsInline preload="metadata" className="aspect-video w-full rounded-lg bg-black" />}
                <label htmlFor="form-media" className="text-xs text-slate-400">
                    JPG, PNG or WebP up to {megabytes(limits.image_kb)}, or MP4 or WebM video up to {megabytes(limits.video_kb)}. Wide images (about 1600 × 600) look best. Saved as soon as it uploads.
                </label>
                <input ref={fileInput} id="form-media" type="file" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm"
                    disabled={progress !== null} aria-invalid={error ? true : undefined}
                    onChange={(event) => event.target.files?.[0] && upload(event.target.files[0])}
                    className="text-sm text-slate-300 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-800 file:px-3 file:py-2 file:text-slate-100 hover:file:bg-slate-700" />
                {progress !== null && <progress value={progress} max={100} className="w-full accent-emerald-500">{progress}%</progress>}
                {error && <p role="alert" className="text-sm text-rose-400">{error}</p>}
                {media && <Button type="button" variant="secondary" onClick={remove} className="self-start">Remove banner</Button>}
            </div>
        </div>
    );
}

/**
 * A tiny drawing of each layout so the choice is easy to see.
 */
function LayoutSketch({ layout }: { layout: FormLayoutValue }) {
    if (layout === 'designed') {
        return (
            <span aria-hidden="true" className="flex h-16 flex-col overflow-hidden rounded border border-slate-700">
                <span className="flex h-7 items-end bg-gradient-to-br from-emerald-700 to-slate-800 px-1.5 pb-1"><span className="h-1.5 w-12 rounded bg-white/80" /></span>
                <span className="flex flex-1 gap-1.5 p-1.5">
                    <span className="flex w-2/5 flex-col gap-1"><span className="h-1 rounded bg-slate-600" /><span className="h-1 w-3/4 rounded bg-slate-600" /></span>
                    <span className="flex-1 rounded bg-slate-700" />
                </span>
            </span>
        );
    }

    return (
        <span aria-hidden="true" className="flex h-16 flex-col items-center gap-1 overflow-hidden rounded border border-slate-700 p-1.5">
            <span className="h-3 w-1/2 rounded bg-slate-600" />
            <span className="h-1 w-1/3 rounded bg-slate-500" />
            <span className="w-1/2 flex-1 rounded bg-slate-700" />
        </span>
    );
}
