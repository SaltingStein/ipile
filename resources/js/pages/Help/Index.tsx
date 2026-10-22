import { Link } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../components/AppLayout';
import EmptyState from '../../components/ui/EmptyState';
import TextField from '../../components/ui/TextField';

interface HelpArticleSummary {
    slug: string;
    title: string;
    summary: string;
}

export default function HelpIndex({ articles }: { articles: HelpArticleSummary[] }) {
    const [search, setSearch] = useState('');
    const term = search.trim().toLowerCase();
    const matches = term === '' ? articles : articles.filter((article) => `${article.title} ${article.summary}`.toLowerCase().includes(term));

    return (
        <AppLayout title="Help">
            <h1 className="text-2xl font-bold">Help</h1>
            <TextField name="help-search" label="Search help" type="search" value={search} onChange={setSearch} placeholder="e.g. two-factor" />
            {matches.length === 0 ? (
                <EmptyState title={articles.length === 0 ? 'No help articles yet' : 'No articles match your search'}
                    description={articles.length === 0 ? undefined : 'Try a different word.'} />
            ) : (
                <ul className="grid gap-3 sm:grid-cols-2" aria-live="polite">
                    {matches.map((article) => (
                        <li key={article.slug}>
                            <Link href={`/help/${article.slug}`} className="block h-full rounded-xl border border-slate-800 bg-slate-900 p-5 transition hover:border-emerald-500 focus-visible:outline-2 focus-visible:outline-emerald-400">
                                <h2 className="font-semibold text-slate-50">{article.title}</h2>
                                {article.summary && <p className="mt-1.5 text-sm text-slate-400">{article.summary}</p>}
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </AppLayout>
    );
}
