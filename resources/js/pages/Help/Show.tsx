import { Link } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';

interface HelpArticleProps {
    article: {
        slug: string;
        title: string;
        summary: string;
        html: string;
    };
}

/**
 * One help article. The HTML comes from a Markdown file in resources/help, rendered on the server with raw HTML stripped.
 */
export default function HelpShow({ article }: HelpArticleProps) {
    return (
        <AppLayout title={article.title}>
            <Link href="/help" className="text-sm text-emerald-400 hover:text-emerald-300">← All help articles</Link>
            <h1 className="text-2xl font-bold">{article.title}</h1>
            {article.summary && <p className="text-slate-400">{article.summary}</p>}
            <article className="help-article max-w-3xl" dangerouslySetInnerHTML={{ __html: article.html }} />
        </AppLayout>
    );
}
