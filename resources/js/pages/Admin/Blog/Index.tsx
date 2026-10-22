import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import AdminLayout from '../../../components/AdminLayout';
import { formatPostDate } from '../../../components/blog/postDate';
import Button from '../../../components/ui/Button';
import Checkbox from '../../../components/ui/Checkbox';
import EmptyState from '../../../components/ui/EmptyState';
import Panel from '../../../components/ui/Panel';
import TextArea from '../../../components/ui/TextArea';
import TextField from '../../../components/ui/TextField';
import type { BlogPostSummary, PaginatedResource } from '../../../types';

interface BlogCategoryRow {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    posts_count: number;
}

interface AdminBlogProps {
    settings: { enabled: boolean; title: string; description: string | null };
    posts: PaginatedResource<BlogPostSummary>;
    categories: BlogCategoryRow[];
    maxCategories: number;
    publicUrl: string;
}

export default function AdminBlog({ settings, posts, categories, maxCategories, publicUrl }: AdminBlogProps) {
    const form = useForm({ enabled: settings.enabled, title: settings.title, description: settings.description ?? '' });

    function save(event: FormEvent) {
        event.preventDefault();
        form.put('/admin/blog/settings', { preserveScroll: true });
    }

    return (
        <AdminLayout title="Blog">
            <Panel title="Blog settings" description="While the blog is off, visitors cannot open it and it leaves the site menu. You can still write and preview posts.">
                <form onSubmit={save} className="flex max-w-xl flex-col gap-4">
                    <Checkbox label="Blog is on" name="blog-enabled" checked={form.data.enabled}
                        description={`Visitors can read published posts at ${publicUrl}, and Blog appears in the site menu.`}
                        onChange={(enabled) => form.setData('enabled', enabled)} />
                    <TextField label="Blog name" name="blog-title" value={form.data.title} required maxLength={40}
                        hint="Shown in the site menu and at the top of the blog, e.g. Blog, News or Stories."
                        onChange={(title) => form.setData('title', title)} error={form.errors.title} />
                    <TextArea label="Introduction (optional)" name="blog-description" value={form.data.description} maxLength={500}
                        onChange={(description) => form.setData('description', description)} error={form.errors.description} />
                    <div className="flex flex-wrap items-center gap-4">
                        <Button disabled={form.processing}>{form.processing ? 'Saving…' : 'Save settings'}</Button>
                        {settings.enabled && <a href={publicUrl} target="_blank" rel="noreferrer" className="text-sm text-emerald-400 hover:text-emerald-300">View blog ↗</a>}
                    </div>
                </form>
            </Panel>

            <Panel title="Posts" description="Newest changes first. Scheduled posts appear on the blog at their publish time.">
                <div>
                    <Link href="/admin/blog/posts/create" className="inline-flex rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400">
                        New post
                    </Link>
                </div>
                {posts.data.length === 0 ? (
                    <EmptyState title="No posts yet" description="Write your first post. It stays a draft until you publish it." />
                ) : (
                    <ul className="flex flex-col divide-y divide-slate-800">
                        {posts.data.map((post) => (
                            <li key={post.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div className="flex min-w-0 flex-col gap-1">
                                    <Link href={`/admin/blog/posts/${post.id}/edit`} className="font-medium text-slate-100 hover:text-emerald-300">{post.title}</Link>
                                    <p className="text-xs text-slate-400">
                                        <PostStatus post={post} />
                                        {post.author && <> · {post.author.name}</>}
                                        {post.categories && post.categories.length > 0 && <> · {post.categories.map((item) => item.name).join(', ')}</>}
                                    </p>
                                </div>
                                <div className="flex gap-4 text-sm">
                                    <a href={`/blog/${post.slug}`} target="_blank" rel="noreferrer" className="text-slate-300 hover:text-emerald-300">
                                        {post.status === 'published' && !post.is_scheduled && settings.enabled ? 'View ↗' : 'Preview ↗'}
                                    </a>
                                    <Link href={`/admin/blog/posts/${post.id}/edit`} className="text-slate-300 hover:text-emerald-300">Edit</Link>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                {posts.meta.last_page > 1 && (
                    <nav aria-label="Pages" className="flex items-center justify-between text-sm">
                        {posts.links.prev ? <Link href={posts.links.prev} className="text-slate-300 hover:text-emerald-300">← Previous</Link> : <span />}
                        <span className="text-slate-400">Page {posts.meta.current_page} of {posts.meta.last_page}</span>
                        {posts.links.next ? <Link href={posts.links.next} className="text-slate-300 hover:text-emerald-300">Next →</Link> : <span />}
                    </nav>
                )}
            </Panel>

            <CategoriesPanel categories={categories} maxCategories={maxCategories} blogEnabled={settings.enabled} />
        </AdminLayout>
    );
}

function postCount(count: number): string {
    return count === 1 ? '1 post' : `${count} posts`;
}

function CategoriesPanel({ categories, maxCategories, blogEnabled }: { categories: BlogCategoryRow[]; maxCategories: number; blogEnabled: boolean }) {
    const addForm = useForm({ name: '', description: '' });
    const [editingId, setEditingId] = useState<number | null>(null);

    function add(event: FormEvent) {
        event.preventDefault();
        addForm.post('/admin/blog/categories', { preserveScroll: true, onSuccess: () => addForm.reset() });
    }

    function remove(category: BlogCategoryRow) {
        if (window.confirm(`Remove the category “${category.name}”? Its ${postCount(category.posts_count)} stay on the blog; they are only taken out of this category.`)) {
            router.delete(`/admin/blog/categories/${category.id}`, { preserveScroll: true });
        }
    }

    return (
        <Panel title="Categories" description="Sort posts into topics such as Events or Announcements. Visitors can browse each category, and a post can be in several.">
            {categories.length === 0 ? (
                <EmptyState title="No categories yet" description="Add one below, then tick it on the posts that belong in it." />
            ) : (
                <ul className="flex flex-col divide-y divide-slate-800">
                    {categories.map((category) => editingId === category.id ? (
                        <li key={category.id} className="py-3">
                            <CategoryEditor category={category} onDone={() => setEditingId(null)} />
                        </li>
                    ) : (
                        <li key={category.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div className="flex min-w-0 flex-col gap-0.5">
                                <span className="font-medium text-slate-100">{category.name}</span>
                                <span className="text-xs text-slate-400">{postCount(category.posts_count)} · /blog/category/{category.slug}</span>
                            </div>
                            <div className="flex gap-4 text-sm">
                                {blogEnabled && <a href={`/blog/category/${category.slug}`} target="_blank" rel="noreferrer" className="text-slate-300 hover:text-emerald-300">View ↗</a>}
                                <button type="button" onClick={() => setEditingId(category.id)} className="text-slate-300 hover:text-emerald-300">Rename</button>
                                <button type="button" onClick={() => remove(category)} className="text-rose-300 hover:text-rose-200">Remove</button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            {categories.length < maxCategories ? (
                <form onSubmit={add} className="flex max-w-xl flex-col gap-3 border-t border-slate-800 pt-4">
                    <TextField label="New category" name="new-category-name" value={addForm.data.name} required maxLength={60}
                        hint="For example Events, Announcements or Stories."
                        onChange={(name) => addForm.setData('name', name)} error={addForm.errors.name} />
                    <TextArea label="Description (optional)" name="new-category-description" rows={2} value={addForm.data.description} maxLength={300}
                        hint="Shown at the top of the category page."
                        onChange={(description) => addForm.setData('description', description)} error={addForm.errors.description} />
                    <Button disabled={addForm.processing} className="self-start">{addForm.processing ? 'Adding…' : 'Add category'}</Button>
                </form>
            ) : (
                <p className="text-sm text-slate-400">You have the maximum of {maxCategories} categories. Remove one to add another.</p>
            )}
        </Panel>
    );
}

function CategoryEditor({ category, onDone }: { category: BlogCategoryRow; onDone: () => void }) {
    const form = useForm({ name: category.name, description: category.description ?? '' });

    function save(event: FormEvent) {
        event.preventDefault();
        form.put(`/admin/blog/categories/${category.id}`, { preserveScroll: true, onSuccess: onDone });
    }

    return (
        <form onSubmit={save} className="flex max-w-xl flex-col gap-3">
            <TextField label="Name" name={`category-${category.id}-name`} value={form.data.name} required maxLength={60}
                hint={`The address stays /blog/category/${category.slug}, so links people shared keep working.`}
                onChange={(name) => form.setData('name', name)} error={form.errors.name} />
            <TextArea label="Description (optional)" name={`category-${category.id}-description`} rows={2} value={form.data.description} maxLength={300}
                onChange={(description) => form.setData('description', description)} error={form.errors.description} />
            <div className="flex gap-3">
                <Button disabled={form.processing}>{form.processing ? 'Saving…' : 'Save'}</Button>
                <Button type="button" variant="secondary" onClick={onDone}>Cancel</Button>
            </div>
        </form>
    );
}

function PostStatus({ post }: { post: BlogPostSummary }) {
    if (post.status === 'draft') {
        return <span className="text-slate-300">Draft</span>;
    }

    if (post.is_scheduled) {
        return <span className="text-sky-300">Scheduled for {formatPostDate(post.published_at)}</span>;
    }

    return <span className="text-emerald-300">Published {formatPostDate(post.published_at)}</span>;
}
