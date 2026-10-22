import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import AdminLayout from '../../../components/AdminLayout';
import RichTextEditor from '../../../components/blog/RichTextEditor';
import { fromLocalDateTimeInput, toLocalDateTimeInput } from '../../../components/forms/builder/fieldUtils';
import Button from '../../../components/ui/Button';
import Checkbox from '../../../components/ui/Checkbox';
import Panel from '../../../components/ui/Panel';
import TextArea from '../../../components/ui/TextArea';
import TextField from '../../../components/ui/TextField';
import type { BlogPostSummary } from '../../../types';
import BlogCategoryPicker from '../../../components/blog/BlogCategoryPicker';

interface EditorProps {
    post: (BlogPostSummary & { excerpt: string | null; body: string }) | null;
    categories: { id: number; name: string }[];
    coverLimitKb: number;
}

type ContentTab = 'write' | 'preview' | 'markdown';

interface PostData {
    title: string;
    slug: string;
    excerpt: string;
    body: string;
    status: 'draft' | 'published';
    published_at: string;
    cover: File | null;
    remove_cover: boolean;
    category_ids: number[];
}

/**
 * "Our first event!" → "our-first-event".
 */
function toSlug(title: string): string {
    return title.normalize('NFKD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 120);
}

/**
 * The XSRF-TOKEN cookie Laravel sets, for the JSON preview request (Inertia requests send it automatically).
 */
function xsrfToken(): string {
    return decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
}

export default function BlogPostEditor({ post, categories, coverLimitKb }: EditorProps) {
    const form = useForm<PostData>({
        title: post?.title ?? '',
        slug: post?.slug ?? '',
        excerpt: post?.excerpt ?? '',
        body: post?.body ?? '',
        status: post?.status ?? 'draft',
        published_at: toLocalDateTimeInput(post?.published_at ?? null),
        cover: null,
        remove_cover: false,
        category_ids: (post?.categories ?? []).flatMap((category) => (category.id === undefined ? [] : [category.id])),
    });
    // New posts take their address from the title until someone edits the address.
    const [slugFollowsTitle, setSlugFollowsTitle] = useState(post === null);
    const [tab, setTab] = useState<ContentTab>('write');
    // Bumped when leaving the Markdown tab, so the editor reloads the text that was typed there.
    const [editorVersion, setEditorVersion] = useState(0);
    const [previewHtml, setPreviewHtml] = useState('');
    const [previewError, setPreviewError] = useState<string | null>(null);
    const coverLimitMb = Math.round((coverLimitKb / 1024) * 10) / 10;

    function save(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({ ...data, published_at: fromLocalDateTimeInput(data.published_at) }));
        form.post(post ? `/admin/blog/posts/${post.id}` : '/admin/blog/posts', {
            preserveScroll: true,
            onSuccess: () => {
                // Once saved, the address is fixed so links people shared keep working.
                setSlugFollowsTitle(false);
                form.setData((data) => ({ ...data, cover: null, remove_cover: false }));
            },
        });
    }

    function switchTab(next: ContentTab) {
        if (tab === 'markdown' && next !== 'markdown') {
            setEditorVersion((version) => version + 1);
        }

        setTab(next);
    }

    /**
     * Upload a picture placed in the post body and return its address.
     */
    async function uploadImage(file: File): Promise<string> {
        if (file.size > coverLimitKb * 1024) {
            throw new Error(`“${file.name}” is larger than ${coverLimitMb} MB. Choose a smaller image.`);
        }

        const body = new FormData();
        body.append('image', file);
        const response = await fetch('/admin/blog/images', {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
            body,
        });
        const json = (await response.json().catch(() => ({}))) as { url?: string; message?: string; errors?: Record<string, string[]> };

        if (!response.ok || !json.url) {
            throw new Error(json.errors?.image?.[0] ?? json.message ?? `“${file.name}” could not be uploaded. Try a smaller image.`);
        }

        return json.url;
    }

    async function showPreview() {
        switchTab('preview');
        setPreviewError(null);

        try {
            const response = await fetch('/admin/blog/preview', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify({ body: form.data.body }),
            });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            setPreviewHtml(((await response.json()) as { html: string }).html);
        } catch {
            setPreviewError('The preview could not be loaded. Your text is safe; try again.');
        }
    }

    function deletePost() {
        if (post && window.confirm(`Delete “${post.title}”? This cannot be undone.`)) {
            router.delete(`/admin/blog/posts/${post.id}`);
        }
    }

    const tabClasses = (name: ContentTab) => `rounded-lg px-3 py-1.5 text-sm font-medium transition ${tab === name ? 'bg-slate-800 text-slate-50' : 'text-slate-400 hover:text-slate-200'}`;

    return (
        <AdminLayout title={post ? 'Edit post' : 'New post'}>
            <div className="flex flex-wrap items-center gap-4 text-sm">
                <Link href="/admin/blog" className="text-slate-400 hover:text-slate-200">← All posts</Link>
                {post && <a href={`/blog/${post.slug}`} target="_blank" rel="noreferrer" className="text-emerald-400 hover:text-emerald-300">Open post ↗</a>}
            </div>

            <form onSubmit={save} className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_18rem]">
                <Panel title="Post">
                    <TextField label="Title" name="post-title" value={form.data.title} required maxLength={200}
                        onChange={(title) => form.setData((data) => ({ ...data, title, slug: slugFollowsTitle ? toSlug(title) : data.slug }))}
                        error={form.errors.title} />
                    <TextField label="Web address" name="post-slug" value={form.data.slug} required maxLength={120}
                        hint={`${window.location.origin}/blog/${form.data.slug || '…'}`}
                        onChange={(slug) => {
                            setSlugFollowsTitle(false);
                            form.setData('slug', slug.toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/-{2,}/g, '-'));
                        }}
                        error={form.errors.slug} />
                    <TextArea label="Summary (optional)" name="post-excerpt" rows={2} value={form.data.excerpt} maxLength={300}
                        hint="Shown on the blog page and in search and social previews. Leave empty to use the start of the post."
                        onChange={(excerpt) => form.setData('excerpt', excerpt)} error={form.errors.excerpt} />

                    <div className="flex flex-col gap-2">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span id="post-body-label" className="text-sm font-medium text-slate-200">Content</span>
                            <div role="tablist" aria-labelledby="post-body-label" className="flex gap-1 rounded-xl border border-slate-800 p-1">
                                <button type="button" role="tab" aria-selected={tab === 'write'} onClick={() => switchTab('write')} className={tabClasses('write')}>Write</button>
                                <button type="button" role="tab" aria-selected={tab === 'preview'} onClick={showPreview} className={tabClasses('preview')}>Preview</button>
                                <button type="button" role="tab" aria-selected={tab === 'markdown'} onClick={() => switchTab('markdown')} className={tabClasses('markdown')}>Markdown</button>
                            </div>
                        </div>
                        {tab === 'write' ? (
                            <>
                                <RichTextEditor key={editorVersion} value={form.data.body} labelledBy="post-body-label" describedBy="post-body-hint"
                                    invalid={Boolean(form.errors.body)} onUploadImage={uploadImage}
                                    onChange={(body) => form.setData('body', body)} />
                                <p id="post-body-hint" className="text-xs text-slate-400">
                                    Use the toolbar, or shortcuts such as Ctrl+B for bold. Paste or drag pictures straight into the text (up to {coverLimitMb} MB each).
                                </p>
                            </>
                        ) : tab === 'markdown' ? (
                            <>
                                <textarea id="post-body" aria-labelledby="post-body-label" value={form.data.body} rows={22} maxLength={200000}
                                    aria-invalid={form.errors.body ? true : undefined} aria-describedby="post-body-markdown-hint"
                                    onChange={(event) => form.setData('body', event.target.value)}
                                    className="rounded-lg border border-slate-700 bg-slate-950 p-3 font-mono text-sm leading-6 text-slate-100 focus:border-emerald-500 focus:outline-none" />
                                <p id="post-body-markdown-hint" className="text-xs text-slate-400">
                                    For people who prefer Markdown: <code>## Heading</code>, <code>**bold**</code>, <code>*italic*</code>, <code>[link](https://…)</code>, <code>- list item</code>.
                                </p>
                            </>
                        ) : previewError ? (
                            <p role="alert" className="text-sm text-rose-400">{previewError}</p>
                        ) : (
                            <div className="help-article blog-article min-h-64 rounded-lg border border-slate-800 p-5" dangerouslySetInnerHTML={{ __html: previewHtml }} />
                        )}
                        {form.errors.body && <p role="alert" className="text-sm text-rose-400">{form.errors.body}</p>}
                    </div>
                </Panel>

                <div className="flex flex-col gap-6">
                    <Panel title="Publishing">
                        <fieldset className="flex flex-col gap-2">
                            <legend className="sr-only">Status</legend>
                            {(['draft', 'published'] as const).map((status) => (
                                <label key={status} htmlFor={`post-status-${status}`}
                                    className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm ${form.data.status === status ? 'border-emerald-500 bg-emerald-500/5' : 'border-slate-800'}`}>
                                    <input id={`post-status-${status}`} type="radio" name="post-status" checked={form.data.status === status}
                                        onChange={() => form.setData('status', status)} className="mt-0.5 size-4 accent-emerald-500" />
                                    <span className="flex flex-col">
                                        <span className="font-medium text-slate-100">{status === 'draft' ? 'Draft' : 'Published'}</span>
                                        <span className="text-xs text-slate-400">{status === 'draft' ? 'Only blog managers can see it.' : 'Visible on the blog from the publish time.'}</span>
                                    </span>
                                </label>
                            ))}
                        </fieldset>
                        {form.data.status === 'published' && (
                            <TextField label="Publish at" name="post-published-at" type="datetime-local" value={form.data.published_at}
                                hint="Leave empty to publish now. Pick a future time to schedule the post."
                                onChange={(value) => form.setData('published_at', value)} error={form.errors.published_at} />
                        )}
                        <Button disabled={form.processing}>{form.processing ? 'Saving…' : form.data.status === 'published' ? 'Save and publish' : 'Save draft'}</Button>
                        {post && <Button type="button" variant="danger" onClick={deletePost}>Delete post</Button>}
                    </Panel>

                    <Panel title="Categories">
                        <BlogCategoryPicker categories={categories} selected={form.data.category_ids} error={form.errors.category_ids}
                            onChange={(ids) => form.setData('category_ids', ids)} />
                    </Panel>

                    <Panel title="Cover image">
                        {post?.cover_url && !form.data.remove_cover && !form.data.cover && (
                            <img src={post.cover_url} alt="Current cover" className="aspect-[16/9] w-full rounded-lg object-cover" />
                        )}
                        <label htmlFor="post-cover" className="text-xs text-slate-400">JPG, PNG or WebP up to {coverLimitMb} MB. About 1600 × 900 looks best.</label>
                        <input id="post-cover" type="file" accept="image/jpeg,image/png,image/webp" aria-invalid={form.errors.cover ? true : undefined}
                            onChange={(event) => form.setData((data) => ({ ...data, cover: event.target.files?.[0] ?? null, remove_cover: false }))}
                            className="text-sm text-slate-300 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-800 file:px-3 file:py-2 file:text-slate-100 hover:file:bg-slate-700" />
                        {form.progress && <progress value={form.progress.percentage} max={100} className="w-full accent-emerald-500" />}
                        {form.errors.cover && <p role="alert" className="text-sm text-rose-400">{form.errors.cover}</p>}
                        {post?.cover_url && (
                            <Checkbox label="Remove the cover image" name="post-remove-cover" checked={form.data.remove_cover}
                                onChange={(remove) => form.setData((data) => ({ ...data, remove_cover: remove, cover: null }))} />
                        )}
                    </Panel>
                </div>
            </form>
        </AdminLayout>
    );
}
