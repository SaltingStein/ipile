import { Head, Link } from '@inertiajs/react';
import PublicLayout from '../../components/PublicLayout';
import { formatPostDate } from '../../components/blog/postDate';
import type { BlogPostSummary } from '../../types';

interface BlogShowProps {
    blog: { title: string };
    post: BlogPostSummary & { html: string };
    isPreview: boolean;
    editUrl: string | null;
}

export default function BlogShow({ blog, post, isPreview, editUrl }: BlogShowProps) {
    return (
        <PublicLayout title={`${post.title} | ${blog.title}`}>
            <Head>
                <meta name="description" content={post.summary} />
                <meta property="og:type" content="article" />
                <meta property="og:title" content={post.title} />
                <meta property="og:description" content={post.summary} />
                {post.cover_url && <meta property="og:image" content={post.cover_url} />}
            </Head>
            {isPreview && (
                <p role="status" className="border-b border-amber-800 bg-amber-950/60 px-6 py-3 text-center text-sm text-amber-100">
                    Preview: only blog managers can see this {post.status === 'draft' ? 'draft' : post.is_scheduled ? 'scheduled post' : 'post while the blog is switched off'}.
                    {editUrl && <> <Link href={editUrl} className="font-semibold underline underline-offset-4">Edit post</Link></>}
                </p>
            )}
            <article className="mx-auto flex w-full max-w-3xl flex-col gap-8 px-6 py-12">
                <header className="flex flex-col gap-4">
                    <Link href="/blog" className="text-sm text-slate-400 hover:text-slate-200">← {blog.title}</Link>
                    <h1 className="text-4xl font-bold tracking-tight text-slate-50 sm:text-5xl">{post.title}</h1>
                    <p className="text-sm text-slate-400">
                        {post.author && <>{post.author.name} · </>}
                        {post.published_at && <><time dateTime={post.published_at}>{formatPostDate(post.published_at)}</time> · </>}
                        {post.reading_minutes} min read
                        {editUrl && !isPreview && <> · <Link href={editUrl} className="text-emerald-400 hover:text-emerald-300">Edit</Link></>}
                    </p>
                    {post.categories && post.categories.length > 0 && (
                        <ul aria-label="Categories" className="flex flex-wrap gap-2 text-sm">
                            {post.categories.map((item) => (
                                <li key={item.slug}>
                                    <Link href={`/blog/category/${item.slug}`} className="rounded-full border border-slate-700 px-3 py-1 text-slate-300 transition hover:border-emerald-500 hover:text-emerald-200">{item.name}</Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </header>
                {post.cover_url && <img src={post.cover_url} alt="" className="aspect-[16/9] w-full rounded-2xl object-cover" />}
                <div className="help-article blog-article" dangerouslySetInnerHTML={{ __html: post.html }} />
            </article>
        </PublicLayout>
    );
}
