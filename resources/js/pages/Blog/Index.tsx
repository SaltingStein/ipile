import { Link } from '@inertiajs/react';
import PublicLayout from '../../components/PublicLayout';
import EmptyState from '../../components/ui/EmptyState';
import { formatPostDate } from '../../components/blog/postDate';
import type { BlogCategoryLink, BlogPostSummary, PaginatedResource } from '../../types';

interface BlogIndexProps {
    blog: { title: string; description: string | null };
    posts: PaginatedResource<BlogPostSummary>;
    categories: BlogCategoryLink[];
    category: (BlogCategoryLink & { description: string | null }) | null;
}

export default function BlogIndex({ blog, posts, categories, category }: BlogIndexProps) {
    // The newest post is shown large on the first page of the whole blog.
    const featured = posts.meta.current_page === 1 && category === null ? posts.data[0] : undefined;
    const rest = featured ? posts.data.slice(1) : posts.data;

    return (
        <PublicLayout title={category ? `${category.name} | ${blog.title}` : blog.title}>
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-10 px-6 py-12">
                <header className="flex flex-col gap-2">
                    {category ? (
                        <>
                            <Link href="/blog" className="text-sm text-slate-400 hover:text-slate-200">← {blog.title}</Link>
                            <h1 className="text-4xl font-bold tracking-tight text-slate-50">{category.name}</h1>
                            {category.description && <p className="max-w-2xl whitespace-pre-line text-lg text-slate-300">{category.description}</p>}
                        </>
                    ) : (
                        <>
                            <h1 className="text-4xl font-bold tracking-tight text-slate-50">{blog.title}</h1>
                            {blog.description && <p className="max-w-2xl whitespace-pre-line text-lg text-slate-300">{blog.description}</p>}
                        </>
                    )}
                </header>

                {categories.length > 0 && (
                    <nav aria-label="Categories" className="-mt-4 flex flex-wrap gap-2 text-sm">
                        <CategoryChip href="/blog" label="All posts" current={category === null} />
                        {categories.map((item) => (
                            <CategoryChip key={item.slug} href={`/blog/category/${item.slug}`} label={item.name} current={category?.slug === item.slug} />
                        ))}
                    </nav>
                )}

                {posts.data.length === 0 && <EmptyState title="No posts yet" description={category ? 'There are no posts in this category yet.' : 'Check back soon.'} />}

                {featured && (
                    <Link href={`/blog/${featured.slug}`} className="group grid overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/80 transition hover:border-slate-600 md:grid-cols-2">
                        {featured.cover_url
                            ? <img src={featured.cover_url} alt="" className="aspect-[16/9] size-full object-cover" />
                            : <div aria-hidden="true" className="aspect-[16/9] bg-gradient-to-br from-emerald-900 to-slate-900" />}
                        <div className="flex flex-col justify-center gap-3 p-6 md:p-10">
                            <PostMeta post={featured} />
                            <h2 className="text-2xl font-bold text-slate-50 group-hover:text-emerald-300 md:text-3xl">{featured.title}</h2>
                            <p className="text-slate-300">{featured.summary}</p>
                        </div>
                    </Link>
                )}

                {rest.length > 0 && (
                    <ul className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {rest.map((post) => (
                            <li key={post.id}>
                                <Link href={`/blog/${post.slug}`} className="group flex h-full flex-col overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/80 transition hover:border-slate-600">
                                    {post.cover_url
                                        ? <img src={post.cover_url} alt="" className="aspect-[16/9] w-full object-cover" />
                                        : <div aria-hidden="true" className="aspect-[16/9] bg-gradient-to-br from-slate-800 to-slate-900" />}
                                    <div className="flex flex-1 flex-col gap-2 p-5">
                                        <PostMeta post={post} />
                                        <h2 className="text-lg font-semibold text-slate-50 group-hover:text-emerald-300">{post.title}</h2>
                                        <p className="line-clamp-3 text-sm text-slate-400">{post.summary}</p>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                {posts.meta.last_page > 1 && (
                    <nav aria-label="Pages" className="flex items-center justify-between text-sm">
                        {posts.links.prev ? <Link href={posts.links.prev} className="text-slate-300 hover:text-emerald-300">← Newer posts</Link> : <span />}
                        <span className="text-slate-400">Page {posts.meta.current_page} of {posts.meta.last_page}</span>
                        {posts.links.next ? <Link href={posts.links.next} className="text-slate-300 hover:text-emerald-300">Older posts →</Link> : <span />}
                    </nav>
                )}
            </div>
        </PublicLayout>
    );
}

function PostMeta({ post }: { post: BlogPostSummary }) {
    return (
        <p className="text-xs uppercase tracking-wide text-slate-400">
            {post.categories && post.categories.length > 0 && <span className="text-emerald-300">{post.categories.map((item) => item.name).join(', ')} · </span>}
            <time dateTime={post.published_at ?? undefined}>{formatPostDate(post.published_at)}</time> · {post.reading_minutes} min read
        </p>
    );
}

function CategoryChip({ href, label, current }: { href: string; label: string; current: boolean }) {
    return (
        <Link href={href} aria-current={current ? 'page' : undefined}
            className={`rounded-full border px-3 py-1 transition ${current ? 'border-emerald-500 bg-emerald-500/10 font-semibold text-emerald-200' : 'border-slate-700 text-slate-300 hover:border-slate-500 hover:text-slate-50'}`}>
            {label}
        </Link>
    );
}
