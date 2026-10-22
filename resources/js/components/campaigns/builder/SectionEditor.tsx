import TextArea from '../../ui/TextArea';
import TextField from '../../ui/TextField';
import { fromLocalDateTimeInput, toLocalDateTimeInput } from '../../forms/builder/fieldUtils';
import { ButtonsEditor, ImagePicker, ItemsEditor, VideoField, orNull } from './fields';
import type { BuilderErrors, ImageUploader } from './fields';
import type { CampaignFormData, CampaignHero, CampaignSection } from '../types';

interface SectionEditorProps {
    section: CampaignSection;
    path: string;
    errors: BuilderErrors;
    forms: CampaignFormData[];
    imageUrls: Record<string, string | null>;
    imageLimitKb: number;
    onUpload: ImageUploader;
    onChange: (section: CampaignSection) => void;
    onImageUploaded: (reference: string, url: string) => void;
}

/**
 * The fields of one section in the campaign builder.
 */
export default function SectionEditor({ section, path, errors, forms, imageUrls, imageLimitKb, onUpload, onChange, onImageUploaded }: SectionEditorProps) {
    const id = `section-${section.id}`;
    const set = (patch: Partial<CampaignSection>) => onChange({ ...section, ...patch } as CampaignSection);
    const heading = (required = false) => (
        <TextField label={required ? 'Heading' : 'Heading (optional)'} name={`${id}-heading`} maxLength={150}
            value={'heading' in section ? (section.heading ?? '') : ''} onChange={(value) => set({ heading: orNull(value) })} error={errors[`${path}.heading`]} />
    );

    switch (section.type) {
        case 'text':
            return (
                <>
                    {heading()}
                    <TextArea label="Text" name={`${id}-body`} rows={6} maxLength={5000} value={section.body ?? ''}
                        hint="Leave an empty line between paragraphs."
                        onChange={(body) => set({ body: orNull(body) })} error={errors[`${path}.body`]} />
                </>
            );
        case 'features':
            return (
                <>
                    {heading()}
                    <ItemsEditor label="Features" path={`${path}.items`} items={section.items} max={6} errors={errors} blank={{ title: 'A benefit', text: null }}
                        onChange={(items) => set({ items })}>
                        {(item, index, update) => (
                            <>
                                <TextField label="Title" name={`${id}-item-${index}-title`} value={item.title ?? ''} maxLength={80}
                                    onChange={(title) => update({ title: orNull(title) })} error={errors[`${path}.items.${index}.title`]} />
                                <TextArea label="Text (optional)" name={`${id}-item-${index}-text`} rows={2} value={item.text ?? ''} maxLength={300}
                                    onChange={(text) => update({ text: orNull(text) })} error={errors[`${path}.items.${index}.text`]} />
                            </>
                        )}
                    </ItemsEditor>
                </>
            );
        case 'image':
            return (
                <>
                    <ImagePicker id={`${id}-image`} label="Picture" imageUrl={section.image ? (imageUrls[section.image] ?? null) : null} limitKb={imageLimitKb}
                        error={errors[`${path}.image`]} onUpload={onUpload}
                        onChange={(image) => {
                            if (image) {
                                onImageUploaded(image.reference, image.url);
                            }

                            set({ image: image?.reference ?? null });
                        }} />
                    <TextField label="Description for screen readers" name={`${id}-alt`} value={section.alt ?? ''} maxLength={200}
                        hint="Say what the picture shows." onChange={(alt) => set({ alt: orNull(alt) })} error={errors[`${path}.alt`]} />
                    <TextField label="Caption (optional)" name={`${id}-caption`} value={section.caption ?? ''} maxLength={200}
                        onChange={(caption) => set({ caption: orNull(caption) })} error={errors[`${path}.caption`]} />
                </>
            );
        case 'video':
            return (
                <>
                    <VideoField id={`${id}-video`} value={section.video_url} error={errors[`${path}.video_url`]} onChange={(video_url) => set({ video_url })} />
                    <TextField label="Caption (optional)" name={`${id}-caption`} value={section.caption ?? ''} maxLength={200}
                        onChange={(caption) => set({ caption: orNull(caption) })} error={errors[`${path}.caption`]} />
                </>
            );
        case 'cta':
            return (
                <>
                    {heading(true)}
                    <TextArea label="Text (optional)" name={`${id}-text`} rows={2} value={section.text ?? ''} maxLength={400}
                        onChange={(text) => set({ text: orNull(text) })} error={errors[`${path}.text`]} />
                    <ButtonsEditor idPrefix={id} path={`${path}.buttons`} buttons={section.buttons} min={1} errors={errors} onChange={(buttons) => set({ buttons })} />
                </>
            );
        case 'form':
            return (
                <>
                    <div className="flex flex-col gap-1.5">
                        <label htmlFor={`${id}-form`} className="text-sm font-medium text-slate-200">Form</label>
                        <select id={`${id}-form`} value={section.form_id ?? ''} onChange={(event) => set({ form_id: event.target.value === '' ? null : Number(event.target.value) })}
                            aria-invalid={errors[`${path}.form_id`] ? true : undefined}
                            className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 aria-invalid:border-rose-500">
                            <option value="">Choose a form…</option>
                            {forms.map((form) => <option key={form.id} value={form.id}>{form.title}</option>)}
                        </select>
                        {errors[`${path}.form_id`]
                            ? <p className="text-sm text-rose-400">{errors[`${path}.form_id`]}</p>
                            : <p className="text-xs text-slate-400">{forms.length === 0 ? 'Publish a site form in Admin → Forms first.' : 'Answers are saved with the form’s other responses in Admin → Forms. Buttons linking to #signup jump here.'}</p>}
                    </div>
                    {heading()}
                    <TextArea label="Text (optional)" name={`${id}-text`} rows={2} value={section.text ?? ''} maxLength={400}
                        onChange={(text) => set({ text: orNull(text) })} error={errors[`${path}.text`]} />
                </>
            );
        case 'countdown':
            return (
                <>
                    {heading()}
                    <TextField label="Count down to" name={`${id}-target`} type="datetime-local" value={toLocalDateTimeInput(section.target)}
                        hint="In your time zone. Visitors see the time left wherever they are."
                        onChange={(value) => set({ target: fromLocalDateTimeInput(value) })} error={errors[`${path}.target`]} />
                </>
            );
        case 'faq':
            return (
                <>
                    {heading()}
                    <ItemsEditor label="Questions" path={`${path}.items`} items={section.items} max={12} errors={errors} blank={{ question: null, answer: null }}
                        onChange={(items) => set({ items })}>
                        {(item, index, update) => (
                            <>
                                <TextField label="Question" name={`${id}-item-${index}-question`} value={item.question ?? ''} maxLength={200}
                                    onChange={(question) => update({ question: orNull(question) })} error={errors[`${path}.items.${index}.question`]} />
                                <TextArea label="Answer" name={`${id}-item-${index}-answer`} rows={3} value={item.answer ?? ''} maxLength={1000}
                                    onChange={(answer) => update({ answer: orNull(answer) })} error={errors[`${path}.items.${index}.answer`]} />
                            </>
                        )}
                    </ItemsEditor>
                </>
            );
        case 'quote':
            return (
                <>
                    <TextArea label="Quote" name={`${id}-quote`} rows={3} value={section.quote ?? ''} maxLength={600}
                        onChange={(quote) => set({ quote: orNull(quote) })} error={errors[`${path}.quote`]} />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <TextField label="Name (optional)" name={`${id}-name`} value={section.name ?? ''} maxLength={80}
                            onChange={(name) => set({ name: orNull(name) })} error={errors[`${path}.name`]} />
                        <TextField label="Role (optional)" name={`${id}-role`} value={section.role ?? ''} maxLength={80}
                            onChange={(role) => set({ role: orNull(role) })} error={errors[`${path}.role`]} />
                    </div>
                </>
            );
        case 'stats':
            return (
                <ItemsEditor label="Figures" path={`${path}.items`} items={section.items} max={4} errors={errors} blank={{ value: '0', label: 'Label' }}
                    onChange={(items) => set({ items })}>
                    {(item, index, update) => (
                        <div className="grid gap-2 sm:grid-cols-[8rem_1fr]">
                            <TextField label="Figure" name={`${id}-item-${index}-value`} value={item.value ?? ''} maxLength={20}
                                onChange={(value) => update({ value: orNull(value) })} error={errors[`${path}.items.${index}.value`]} />
                            <TextField label="Label" name={`${id}-item-${index}-label`} value={item.label ?? ''} maxLength={60}
                                onChange={(label) => update({ label: orNull(label) })} error={errors[`${path}.items.${index}.label`]} />
                        </div>
                    )}
                </ItemsEditor>
            );
    }
}

export function HeroEditor({ hero, errors, imageUrls, imageLimitKb, onUpload, onChange, onImageUploaded }: {
    hero: CampaignHero;
    errors: BuilderErrors;
    imageUrls: Record<string, string | null>;
    imageLimitKb: number;
    onUpload: ImageUploader;
    onChange: (hero: CampaignHero) => void;
    onImageUploaded: (reference: string, url: string) => void;
}) {
    const set = (patch: Partial<CampaignHero>) => onChange({ ...hero, ...patch });

    return (
        <div className="flex flex-col gap-4">
            <TextField label="Small heading above (optional)" name="hero-eyebrow" value={hero.eyebrow ?? ''} maxLength={60} placeholder="e.g. Limited offer"
                onChange={(eyebrow) => set({ eyebrow: orNull(eyebrow) })} error={errors['hero.eyebrow']} />
            <TextField label="Headline" name="hero-headline" value={hero.headline} required maxLength={150}
                onChange={(headline) => set({ headline })} error={errors['hero.headline']} />
            <TextArea label="Text (optional)" name="hero-text" rows={3} value={hero.text ?? ''} maxLength={600}
                onChange={(text) => set({ text: orNull(text) })} error={errors['hero.text']} />
            <ButtonsEditor idPrefix="hero" path="hero.buttons" buttons={hero.buttons} min={0} errors={errors} onChange={(buttons) => set({ buttons })} />

            <fieldset className="flex flex-col gap-2">
                <legend className="mb-1 text-sm font-medium text-slate-200">Banner</legend>
                <div className="flex flex-wrap gap-2">
                    {([['none', 'None'], ['image', 'Picture'], ['video', 'YouTube or Vimeo video']] as const).map(([value, label]) => (
                        <label key={value} htmlFor={`hero-media-${value}`}
                            className={`flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-1.5 text-sm ${hero.media === value ? 'border-emerald-500 bg-emerald-500/5 text-slate-100' : 'border-slate-700 text-slate-300'}`}>
                            <input id={`hero-media-${value}`} type="radio" name="hero-media" checked={hero.media === value}
                                onChange={() => set({ media: value })} className="size-4 accent-emerald-500" />
                            {label}
                        </label>
                    ))}
                </div>
            </fieldset>
            {hero.media === 'image' && (
                <>
                    <ImagePicker id="hero-image" label="Banner picture" imageUrl={hero.image ? (imageUrls[hero.image] ?? null) : null} limitKb={imageLimitKb}
                        error={errors['hero.image']} onUpload={onUpload}
                        onChange={(image) => {
                            if (image) {
                                onImageUploaded(image.reference, image.url);
                            }

                            set({ image: image?.reference ?? null });
                        }} />
                    <TextField label="Description for screen readers (optional)" name="hero-image-alt" value={hero.image_alt ?? ''} maxLength={200}
                        hint="Leave empty if the picture is only decoration." onChange={(alt) => set({ image_alt: orNull(alt) })} error={errors['hero.image_alt']} />
                </>
            )}
            {hero.media === 'video' && <VideoField id="hero-video" value={hero.video_url} error={errors['hero.video_url']} onChange={(video_url) => set({ video_url })} />}
        </div>
    );
}
