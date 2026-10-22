import type { SiteLanguages } from '../../types';

/**
 * The language menu on public pages. Language names are written in their own language and never translated.
 */
export default function LanguageSwitcher({ languages, value, onChange, className = '' }: {
    languages: SiteLanguages;
    value: string | null;
    onChange: (code: string | null) => void;
    className?: string;
}) {
    return (
        <span translate="no" className={`inline-flex items-center gap-1.5 ${className}`}>
            <label htmlFor="site-language" className="sr-only">Language</label>
            <span aria-hidden="true">🌐</span>
            <select id="site-language" value={value ?? ''} onChange={(event) => onChange(event.target.value === '' ? null : event.target.value)}
                className="rounded-md border border-slate-700 bg-slate-950 px-2 py-1 text-sm text-slate-200 focus:border-emerald-500 focus:outline-none">
                <option value="">English</option>
                {languages.languages.map((language) => (
                    <option key={language.code} value={language.code} lang={language.code}>{language.native}</option>
                ))}
            </select>
        </span>
    );
}
