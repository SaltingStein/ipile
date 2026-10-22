import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import type { DragEvent, ReactNode } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import AdminLayout from '../../../components/AdminLayout';
import CampaignPageView from '../../../components/campaigns/CampaignPageView';
import TemplateSketch from '../../../components/campaigns/TemplateSketch';
import SectionEditor, { HeroEditor } from '../../../components/campaigns/builder/SectionEditor';
import { duplicateSection, newSection, sectionSummary } from '../../../components/campaigns/builder/sectionDefaults';
import type { BuilderErrors } from '../../../components/campaigns/builder/fields';
import { accentColours, sectionLabels } from '../../../components/campaigns/types';
import type {
    CampaignAccent, CampaignFormData, CampaignHero, CampaignPageData, CampaignSection, CampaignSectionType, CampaignTemplateValue,
} from '../../../components/campaigns/types';
import ShareFormDialog from '../../../components/forms/builder/ShareFormDialog';
import { fromLocalDateTimeInput, moveItem, toLocalDateTimeInput } from '../../../components/forms/builder/fieldUtils';
import Button from '../../../components/ui/Button';
import Checkbox from '../../../components/ui/Checkbox';
import Panel from '../../../components/ui/Panel';
import StatusMessage from '../../../components/ui/StatusMessage';
import TextArea from '../../../components/ui/TextArea';
import TextField from '../../../components/ui/TextField';

interface BuilderProps {
    campaign: {
        id: number;
        title: string;
        slug: string;
        template: CampaignTemplateValue;
        status: 'draft' | 'published';
        accent: CampaignAccent;
        meta_description: string | null;
        ends_at: string | null;
        ended_message: string | null;
        hero: CampaignHero;
        sections: CampaignSection[];
        url: string;
        is_home_page: boolean;
        stats: { views: number; cta_clicks: number; conversions: number };
    };
    imageUrls: Record<string, string | null>;
    forms: CampaignFormData[];
    templates: { value: CampaignTemplateValue; label: string; description: string }[];
    accents: CampaignAccent[];
    sectionTypes: CampaignSectionType[];
    maxSections: number;
    imageLimitKb: number;
    menus: { path: string; site: boolean; workspace: boolean } | null;
}

interface BuilderData {
    title: string;
    slug: string;
    template: CampaignTemplateValue;
    status: 'draft' | 'published';
    accent: CampaignAccent;
    meta_description: string;
    ends_at: string;
    ended_message: string;
    hero: CampaignHero;
    sections: CampaignSection[];
}

type Tab = 'content' | 'design' | 'settings';

const SETTINGS_FIELDS = ['title', 'slug', 'meta_description', 'ends_at', 'ended_message', 'status'];

/**
 * The XSRF-TOKEN cookie Laravel sets, for the picture upload request.
 */
function xsrfToken(): string {
    return decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
}

export default function CampaignBuilder({ campaign, imageUrls: savedImageUrls, forms, templates, accents, sectionTypes, maxSections, imageLimitKb, menus }: BuilderProps) {
    const form = useForm<BuilderData>({
        title: campaign.title,
        slug: campaign.slug,
        template: campaign.template,
        status: campaign.status,
        accent: campaign.accent,
        meta_description: campaign.meta_description ?? '',
        ends_at: toLocalDateTimeInput(campaign.ends_at),
        ended_message: campaign.ended_message ?? '',
        hero: campaign.hero,
        sections: campaign.sections,
    });
    const [imageUrls, setImageUrls] = useState(savedImageUrls);
    const [tab, setTab] = useState<Tab>('content');
    const [openId, setOpenId] = useState<string | null>('hero');
    const [draggedId, setDraggedId] = useState<string | null>(null);
    const [dropIndex, setDropIndex] = useState<number | null>(null);
    const [fullScreen, setFullScreen] = useState(false);
    const [shareOpen, setShareOpen] = useState(false);
    const errors = form.errors as BuilderErrors;
    const errorCount = Object.keys(errors).length;
    const sections = form.data.sections;
    const hasForm = sections.some((section) => section.type === 'form');

    useUnsavedChangesWarning(form.isDirty);

    const previewPage = useMemo<CampaignPageData>(() => {
        const endsAt = fromLocalDateTimeInput(form.data.ends_at);
        const formId = sections.find((section) => section.type === 'form')?.form_id;

        return {
            title: form.data.title,
            slug: form.data.slug,
            template: form.data.template,
            accent: form.data.accent,
            meta_description: form.data.meta_description || null,
            hero: { ...form.data.hero, image_url: form.data.hero.image ? (imageUrls[form.data.hero.image] ?? null) : null },
            sections: sections.map((section) => (section.type === 'image' ? { ...section, image_url: section.image ? (imageUrls[section.image] ?? null) : null } : section)),
            ends_at: endsAt,
            has_ended: endsAt !== null && new Date(endsAt) < new Date(),
            ended_message: form.data.ended_message || 'This campaign has ended. Thank you for your interest.',
            form: forms.find((candidate) => candidate.id === formId) ?? null,
            cover_url: null,
        };
    }, [form.data, sections, imageUrls, forms]);

    function save(status: BuilderData['status'] = form.data.status) {
        const publishing = status === 'published' && campaign.status !== 'published';

        form.transform((data) => ({ ...data, status, ends_at: fromLocalDateTimeInput(data.ends_at) }));
        form.put(`/admin/campaigns/${campaign.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                form.setDefaults({ ...form.data, status });
                form.setData('status', status);

                if (publishing) {
                    setShareOpen(true);
                }
            },
            onError: (newErrors) => {
                // Open the part of the builder that holds the first problem.
                const first = Object.keys(newErrors)[0] ?? '';
                const sectionIndex = first.match(/^sections\.(\d+)\./)?.[1];

                if (SETTINGS_FIELDS.includes(first)) {
                    setTab('settings');
                } else if (first.startsWith('hero')) {
                    setTab('content');
                    setOpenId('hero');
                } else if (sectionIndex !== undefined) {
                    setTab('content');
                    setOpenId(sections[Number(sectionIndex)]?.id ?? null);
                }
            },
        });
    }

    async function uploadImage(file: File): Promise<{ reference: string; url: string }> {
        const body = new FormData();
        body.append('image', file);
        const response = await fetch('/admin/campaigns/images', { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() }, body });
        const json = (await response.json().catch(() => ({}))) as { reference?: string; url?: string; message?: string; errors?: Record<string, string[]> };

        if (!response.ok || !json.reference || !json.url) {
            throw new Error(json.errors?.image?.[0] ?? json.message ?? `“${file.name}” could not be uploaded. Try a smaller picture.`);
        }

        return { reference: json.reference, url: json.url };
    }

    const setSections = (next: CampaignSection[]) => form.setData('sections', next);
    const updateSection = (updated: CampaignSection) => setSections(sections.map((section) => (section.id === updated.id ? updated : section)));
    const rememberImage = (reference: string, url: string) => setImageUrls((urls) => ({ ...urls, [reference]: url }));

    function addSection(type: CampaignSectionType) {
        const section = newSection(type, forms[0]?.id ?? null);
        const openIndex = sections.findIndex((candidate) => candidate.id === openId);
        const at = openIndex === -1 ? sections.length : openIndex + 1;

        setSections([...sections.slice(0, at), section, ...sections.slice(at)]);
        setOpenId(section.id);
    }

    function dragOver(event: DragEvent<HTMLLIElement>, index: number) {
        if (draggedId === null) {
            return;
        }

        event.preventDefault();
        const box = event.currentTarget.getBoundingClientRect();
        setDropIndex(event.clientY < box.top + box.height / 2 ? index : index + 1);
    }

    function drop() {
        const from = sections.findIndex((section) => section.id === draggedId);

        if (from !== -1 && dropIndex !== null) {
            setSections(moveItem(sections, from, dropIndex));
        }

        setDraggedId(null);
        setDropIndex(null);
    }

    function move(index: number, offset: number) {
        setSections(moveItem(sections, index, offset < 0 ? index - 1 : index + 2));
    }

    function deletePage() {
        if (window.confirm(`Delete “${campaign.title}”? Its address stops working and this cannot be undone. Sign-ups stay with the form.`)) {
            router.delete(`/admin/campaigns/${campaign.id}`);
        }
    }

    const tabClasses = (name: Tab) => `flex-1 rounded-lg px-3 py-1.5 text-sm font-medium transition ${tab === name ? 'bg-slate-800 text-slate-50' : 'text-slate-400 hover:text-slate-200'}`;
    const published = campaign.status === 'published';

    return (
        <AdminLayout title={form.data.title || 'Campaign page'}>
            <div className="flex flex-wrap items-center gap-3 text-sm">
                <Link href="/admin/campaigns" className="text-slate-400 hover:text-slate-200">← All campaign pages</Link>
                <span className={`rounded-full px-2 py-0.5 text-xs ${published ? 'bg-emerald-500/10 text-emerald-300' : 'bg-slate-800 text-slate-300'}`}>{published ? 'Published' : 'Draft'}</span>
                {form.isDirty && <span className="text-xs text-amber-300">Unsaved changes</span>}
                <div className="ml-auto flex flex-wrap items-center gap-2">
                    <a href={campaign.url} target="_blank" rel="noreferrer" className="px-2 text-emerald-400 hover:text-emerald-300">{published ? 'View page ↗' : 'Preview saved draft ↗'}</a>
                    {published && <Button type="button" variant="secondary" onClick={() => setShareOpen(true)}>Share</Button>}
                    <Button type="button" variant="secondary" disabled={form.processing} onClick={() => save()}>{form.processing ? 'Saving…' : 'Save'}</Button>
                    {published
                        ? <Button type="button" variant="secondary" disabled={form.processing} onClick={() => save('draft')}>Unpublish</Button>
                        : <Button type="button" disabled={form.processing} onClick={() => save('published')}>Publish</Button>}
                </div>
            </div>

            {errorCount > 0 && <StatusMessage tone="error" message={`Please fix ${errorCount === 1 ? 'the highlighted problem' : `the ${errorCount} highlighted problems`} before saving.`} />}

            <div className="grid gap-6 xl:grid-cols-[26rem_minmax(0,1fr)]">
                <div className="flex min-w-0 flex-col gap-4">
                    <div role="tablist" aria-label="Builder" className="flex gap-1 rounded-xl border border-slate-800 bg-slate-900/80 p-1">
                        <button type="button" role="tab" aria-selected={tab === 'content'} onClick={() => setTab('content')} className={tabClasses('content')}>Content</button>
                        <button type="button" role="tab" aria-selected={tab === 'design'} onClick={() => setTab('design')} className={tabClasses('design')}>Design</button>
                        <button type="button" role="tab" aria-selected={tab === 'settings'} onClick={() => setTab('settings')} className={tabClasses('settings')}>Settings</button>
                    </div>

                    {tab === 'content' && (
                        <>
                            <Collapsible title="Top of the page" summary={form.data.hero.headline} open={openId === 'hero'} hasError={Object.keys(errors).some((key) => key.startsWith('hero'))}
                                onToggle={() => setOpenId(openId === 'hero' ? null : 'hero')}>
                                <HeroEditor hero={form.data.hero} errors={errors} imageUrls={imageUrls} imageLimitKb={imageLimitKb} onUpload={uploadImage}
                                    onImageUploaded={rememberImage} onChange={(hero) => form.setData('hero', hero)} />
                            </Collapsible>

                            <section aria-labelledby="sections-heading" className="flex flex-col gap-2">
                                <div className="flex items-baseline justify-between">
                                    <h2 id="sections-heading" className="text-sm font-semibold uppercase tracking-wide text-slate-400">Sections</h2>
                                    <span className="text-xs text-slate-500">Drag to reorder · {sections.length}/{maxSections}</span>
                                </div>
                                {errors.sections && <p className="text-sm text-rose-400">{errors.sections}</p>}
                                <ol className="flex flex-col gap-2" onDragOver={(event) => draggedId && event.preventDefault()} onDrop={drop}>
                                    {sections.map((section, index) => {
                                        const path = `sections.${index}`;

                                        return (
                                            <li key={section.id} onDragOver={(event) => dragOver(event, index)}
                                                className={`relative ${draggedId === section.id ? 'opacity-40' : ''}`}>
                                                {dropIndex === index && <span aria-hidden="true" className="absolute -top-1.5 left-0 right-0 h-0.5 rounded bg-emerald-400" />}
                                                {dropIndex === index + 1 && index === sections.length - 1 && <span aria-hidden="true" className="absolute -bottom-1.5 left-0 right-0 h-0.5 rounded bg-emerald-400" />}
                                                <Collapsible
                                                    title={sectionLabels[section.type].label}
                                                    icon={sectionLabels[section.type].icon}
                                                    summary={sectionSummary(section)}
                                                    open={openId === section.id}
                                                    hasError={Object.keys(errors).some((key) => key.startsWith(`${path}.`))}
                                                    onToggle={() => setOpenId(openId === section.id ? null : section.id)}
                                                    dragHandle={{
                                                        onDragStart: (event) => {
                                                            event.dataTransfer.effectAllowed = 'move';
                                                            event.dataTransfer.setData('text/plain', sectionLabels[section.type].label);
                                                            setDraggedId(section.id);
                                                        },
                                                        onDragEnd: () => {
                                                            setDraggedId(null);
                                                            setDropIndex(null);
                                                        },
                                                    }}
                                                    actions={(
                                                        <>
                                                            <IconButton label="Move up" disabled={index === 0} onClick={() => move(index, -1)}>↑</IconButton>
                                                            <IconButton label="Move down" disabled={index === sections.length - 1} onClick={() => move(index, 1)}>↓</IconButton>
                                                            <IconButton label="Duplicate" disabled={sections.length >= maxSections || section.type === 'form'}
                                                                onClick={() => setSections([...sections.slice(0, index + 1), duplicateSection(section), ...sections.slice(index + 1)])}>⧉</IconButton>
                                                            <IconButton label="Remove" onClick={() => setSections(sections.filter((candidate) => candidate.id !== section.id))}>✕</IconButton>
                                                        </>
                                                    )}>
                                                    <SectionEditor section={section} path={path} errors={errors} forms={forms} imageUrls={imageUrls} imageLimitKb={imageLimitKb}
                                                        onUpload={uploadImage} onImageUploaded={rememberImage} onChange={updateSection} />
                                                </Collapsible>
                                            </li>
                                        );
                                    })}
                                </ol>
                                {sections.length === 0 && <p className="rounded-xl border border-dashed border-slate-700 p-4 text-center text-sm text-slate-400">No sections yet. Add one below.</p>}
                            </section>

                            <Panel title="Add a section" description={openId && openId !== 'hero' ? 'It goes under the open section.' : 'It goes at the end of the page.'}>
                                <div className="grid grid-cols-2 gap-2">
                                    {sectionTypes.map((type) => {
                                        const disabled = sections.length >= maxSections || (type === 'form' && hasForm);

                                        return (
                                            <button key={type} type="button" disabled={disabled} onClick={() => addSection(type)}
                                                title={type === 'form' && hasForm ? 'A page can have one form.' : sectionLabels[type].description}
                                                className="flex items-start gap-2 rounded-lg border border-slate-800 p-2.5 text-left transition hover:border-emerald-500/60 hover:bg-slate-800/50 disabled:cursor-not-allowed disabled:opacity-40">
                                                <span aria-hidden="true" className="mt-0.5 w-5 text-center text-emerald-300">{sectionLabels[type].icon}</span>
                                                <span className="flex flex-col">
                                                    <span className="text-sm font-medium text-slate-100">{sectionLabels[type].label}</span>
                                                    <span className="text-xs text-slate-400">{sectionLabels[type].description}</span>
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                            </Panel>
                        </>
                    )}

                    {tab === 'design' && (
                        <>
                            <Panel title="Template" description="Changes the layout only; your content stays.">
                                <fieldset className="flex flex-col gap-3">
                                    <legend className="sr-only">Template</legend>
                                    {templates.map((template) => (
                                        <label key={template.value} htmlFor={`builder-template-${template.value}`}
                                            className={`grid cursor-pointer grid-cols-[7rem_1fr] items-center gap-3 rounded-xl border p-2 ${form.data.template === template.value ? 'border-emerald-500 bg-emerald-500/5' : 'border-slate-800 hover:border-slate-600'}`}>
                                            <TemplateSketch template={template.value} />
                                            <span className="flex items-start gap-2">
                                                <input id={`builder-template-${template.value}`} type="radio" name="builder-template" checked={form.data.template === template.value}
                                                    onChange={() => form.setData('template', template.value)} className="mt-1 size-4 accent-emerald-500" />
                                                <span className="flex flex-col">
                                                    <span className="font-semibold text-slate-100">{template.label}</span>
                                                    <span className="text-xs text-slate-400">{template.description}</span>
                                                </span>
                                            </span>
                                        </label>
                                    ))}
                                </fieldset>
                            </Panel>
                            <Panel title="Accent colour" description="Used for buttons, highlights and figures. Backgrounds follow the site theme.">
                                <fieldset className="flex flex-wrap gap-3">
                                    <legend className="sr-only">Accent colour</legend>
                                    {accents.map((accent) => (
                                        <label key={accent} htmlFor={`accent-${accent}`}
                                            className={`flex cursor-pointer items-center gap-2 rounded-full border px-3 py-1.5 text-sm ${form.data.accent === accent ? 'border-slate-300 text-slate-50' : 'border-slate-700 text-slate-300'}`}>
                                            <input id={`accent-${accent}`} type="radio" name="accent" className="sr-only" checked={form.data.accent === accent} onChange={() => form.setData('accent', accent)} />
                                            <span aria-hidden="true" className="size-4 rounded-full" style={{ backgroundColor: accentColours[accent].colour }} />
                                            {accentColours[accent].label}
                                        </label>
                                    ))}
                                </fieldset>
                            </Panel>
                        </>
                    )}

                    {tab === 'settings' && (
                        <>
                            <Panel title="Page">
                                <TextField label="Page name" name="campaign-title" value={form.data.title} required maxLength={150}
                                    hint="Shown in the browser tab and when the link is shared." onChange={(title) => form.setData('title', title)} error={errors.title} />
                                <TextField label="Web address" name="campaign-slug" value={form.data.slug} required maxLength={120}
                                    hint={`${window.location.origin}/c/${form.data.slug || '…'}${published ? ' · Changing it breaks links you have already shared.' : ''}`}
                                    onChange={(slug) => form.setData('slug', slug.toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/-{2,}/g, '-'))} error={errors.slug} />
                                <TextArea label="Description for search and social media (optional)" name="campaign-meta" rows={2} value={form.data.meta_description} maxLength={300}
                                    onChange={(value) => form.setData('meta_description', value)} error={errors.meta_description} />
                            </Panel>
                            <Panel title="End date" description="After this time, buttons and the form are hidden and visitors see your message instead.">
                                <TextField label="Campaign ends (optional)" name="campaign-ends-at" type="datetime-local" value={form.data.ends_at}
                                    onChange={(value) => form.setData('ends_at', value)} error={errors.ends_at} />
                                <TextArea label="Message after it ends (optional)" name="campaign-ended-message" rows={2} value={form.data.ended_message} maxLength={500}
                                    placeholder="This campaign has ended. Thank you for your interest."
                                    onChange={(value) => form.setData('ended_message', value)} error={errors.ended_message} />
                            </Panel>
                            <Panel title="Home page">
                                <Checkbox label="Show this page at the site’s home page" name="campaign-home-page" checked={campaign.is_home_page}
                                    description={published ? 'Replaces the landing page until you untick this, unpublish the page or it ends.' : 'Publish the page first.'}
                                    disabled={!published && !campaign.is_home_page}
                                    onChange={(checked) => router.put(`/admin/campaigns/${campaign.id}/home-page`, { is_home_page: checked }, { preserveScroll: true, preserveState: true })} />
                            </Panel>
                            <Panel title="Results so far">
                                <dl className="grid grid-cols-3 gap-3 text-center">
                                    {[['Views', campaign.stats.views], ['Button clicks', campaign.stats.cta_clicks], ['Sign-ups', campaign.stats.conversions]].map(([label, value]) => (
                                        <div key={label} className="flex flex-col-reverse rounded-lg border border-slate-800 p-3">
                                            <dt className="text-xs text-slate-400">{label}</dt>
                                            <dd className="text-2xl font-semibold tabular-nums text-slate-50">{Number(value).toLocaleString()}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </Panel>
                            <Panel title="More">
                                <div className="flex flex-wrap gap-3">
                                    <Button type="button" variant="secondary" onClick={() => router.post(`/admin/campaigns/${campaign.id}/duplicate`)}>Copy as a new draft</Button>
                                    <Button type="button" variant="danger" onClick={deletePage}>Delete page</Button>
                                </div>
                            </Panel>
                        </>
                    )}
                </div>

                <div className="flex min-w-0 flex-col gap-2 xl:sticky xl:top-6 xl:self-start">
                    <div className="flex items-center justify-between text-sm">
                        <span className="font-semibold uppercase tracking-wide text-slate-400">Live preview</span>
                        <button type="button" onClick={() => setFullScreen(true)} className="text-emerald-400 hover:text-emerald-300">Full screen</button>
                    </div>
                    <ScaledPreview>
                        <CampaignPageView page={previewPage} renderForm={(campaignForm) => <PreviewForm form={campaignForm} />} />
                    </ScaledPreview>
                </div>
            </div>

            {fullScreen && (
                <div role="dialog" aria-modal="true" aria-label="Full-screen preview" className="fixed inset-0 z-50 overflow-y-auto bg-slate-950"
                    onKeyDown={(event) => event.key === 'Escape' && setFullScreen(false)}>
                    <div className="sticky top-0 z-10 flex items-center justify-between border-b border-slate-800 bg-slate-900/95 px-4 py-2 text-sm">
                        <span className="text-slate-300">Preview of unsaved changes</span>
                        <button type="button" autoFocus onClick={() => setFullScreen(false)} className="rounded-lg border border-slate-700 px-3 py-1 text-slate-100 hover:border-slate-500">Close preview</button>
                    </div>
                    <CampaignPageView page={previewPage} renderForm={(campaignForm) => <PreviewForm form={campaignForm} />} />
                </div>
            )}

            <ShareFormDialog open={shareOpen} onClose={() => setShareOpen(false)} title={form.data.title} url={campaign.url} menus={menus} heading="Your page is live" />
        </AdminLayout>
    );
}

function Collapsible({ title, icon, summary, open, hasError, onToggle, dragHandle, actions, children }: {
    title: string;
    icon?: string;
    summary: string;
    open: boolean;
    hasError: boolean;
    onToggle: () => void;
    dragHandle?: { onDragStart: (event: DragEvent<HTMLSpanElement>) => void; onDragEnd: () => void };
    actions?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className={`rounded-xl border bg-slate-900/80 ${hasError ? 'border-rose-500/70' : open ? 'border-emerald-500/50' : 'border-slate-800'}`}>
            <div className="flex items-center gap-2 px-3 py-2">
                {dragHandle && (
                    <span draggable onDragStart={dragHandle.onDragStart} onDragEnd={dragHandle.onDragEnd} title="Drag to reorder" aria-hidden="true"
                        className="cursor-grab select-none px-1 text-slate-500 hover:text-slate-300 active:cursor-grabbing">⋮⋮</span>
                )}
                <button type="button" aria-expanded={open} onClick={onToggle} className="flex min-w-0 flex-1 items-center gap-2 text-left">
                    {icon && <span aria-hidden="true" className="w-5 text-center text-emerald-300">{icon}</span>}
                    <span className="flex min-w-0 flex-col">
                        <span className="text-sm font-semibold text-slate-100">{title}{hasError && <span className="ml-2 text-xs font-normal text-rose-300">Needs attention</span>}</span>
                        {summary && <span className="truncate text-xs text-slate-400">{summary}</span>}
                    </span>
                </button>
                {actions && <div className="flex shrink-0 items-center gap-0.5">{actions}</div>}
            </div>
            {open && <div className="flex flex-col gap-4 border-t border-slate-800 p-4">{children}</div>}
        </div>
    );
}

function IconButton({ label, disabled = false, onClick, children }: { label: string; disabled?: boolean; onClick: () => void; children: ReactNode }) {
    return (
        <button type="button" title={label} aria-label={label} disabled={disabled} onClick={onClick}
            className="flex size-7 items-center justify-center rounded-md text-sm text-slate-400 transition hover:bg-slate-800 hover:text-slate-100 disabled:opacity-30">
            {children}
        </button>
    );
}

/**
 * The page drawn at desktop width (1280px) and scaled down to fit the column, so the preview shows the real
 * layout rather than a squeezed one.
 */
function ScaledPreview({ children }: { children: ReactNode }) {
    const DESIGN_WIDTH = 1280;
    const frame = useRef<HTMLDivElement>(null);
    const content = useRef<HTMLDivElement>(null);
    const [scale, setScale] = useState(0.5);
    const [height, setHeight] = useState(600);

    useLayoutEffect(() => {
        // Keeps keyboard focus and clicks out of the preview (React 18 has no `inert` prop).
        content.current?.setAttribute('inert', '');
        const observer = new ResizeObserver(() => {
            setScale((frame.current?.clientWidth ?? DESIGN_WIDTH) / DESIGN_WIDTH);
            setHeight(content.current?.scrollHeight ?? 600);
        });

        if (frame.current) {
            observer.observe(frame.current);
        }

        if (content.current) {
            observer.observe(content.current);
        }

        return () => observer.disconnect();
    }, []);

    return (
        <div ref={frame} className="max-h-[calc(100vh-8rem)] overflow-y-auto overflow-x-hidden rounded-xl border border-slate-800 bg-slate-950">
            <div style={{ height: height * scale }}>
                <div ref={content} style={{ width: DESIGN_WIDTH, transform: `scale(${scale})`, transformOrigin: 'top left' }} className="text-slate-100">
                    {children}
                </div>
            </div>
        </div>
    );
}

/**
 * The page's form as visitors will see it, without sending anything.
 */
function PreviewForm({ form }: { form: CampaignFormData }) {
    return (
        <div className="flex flex-col gap-4">
            {form.fields.filter((field) => field.type !== 'heading').slice(0, 6).map((field) => (
                <div key={field.id} className="flex flex-col gap-1.5">
                    <span className="text-sm font-medium text-slate-200">{field.label}{field.required && <span className="text-rose-400"> *</span>}</span>
                    <span className="h-10 rounded-lg border border-slate-700 bg-slate-950" />
                </div>
            ))}
            <span className="self-start rounded-lg bg-[var(--accent)] px-4 py-2 text-sm font-semibold text-slate-950">{form.submit_label}</span>
            {form.closed_reason && <p className="text-sm text-amber-300">Visitors will see: {form.closed_reason}</p>}
        </div>
    );
}

/**
 * Ask before leaving the builder with unsaved changes (closing the tab or following a link).
 */
function useUnsavedChangesWarning(isDirty: boolean) {
    useEffect(() => {
        if (!isDirty) {
            return;
        }

        const beforeUnload = (event: BeforeUnloadEvent) => event.preventDefault();
        const removeInertiaListener = router.on('before', (event) => {
            const visit = event.detail.visit;

            if (visit.method === 'get' && !window.confirm('Leave without saving? Your changes to this page will be lost.')) {
                event.preventDefault();
            }
        });
        window.addEventListener('beforeunload', beforeUnload);

        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            removeInertiaListener();
        };
    }, [isDirty]);
}
