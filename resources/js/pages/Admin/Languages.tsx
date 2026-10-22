import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import AdminLayout from '../../components/AdminLayout';
import Button from '../../components/ui/Button';
import Checkbox from '../../components/ui/Checkbox';
import EmptyState from '../../components/ui/EmptyState';
import Panel from '../../components/ui/Panel';
import StatusMessage from '../../components/ui/StatusMessage';
import TextField from '../../components/ui/TextField';
import type { PaginatedResource } from '../../types';

interface LanguageOption {
    code: string;
    name: string;
    native: string;
    dir: string;
}

interface StringRow {
    id: number;
    text: string;
    translation: string | null;
    is_manual: boolean;
    confirmed: boolean;
}

type Status = 'all' | 'missing' | 'machine' | 'manual' | 'unconfirmed';

interface LanguagesProps {
    settings: { enabled: boolean; languages: string[]; provider: string | null; daily_limit: number };
    languages: LanguageOption[];
    providers: { value: string; label: string }[];
    maxDailyLimit: number;
    machine: { available: boolean; remaining_today: number };
    progress: { code: string; name: string; total: number; translated: number; manual: number }[];
    unconfirmed: number;
    filters: { locale: string | null; status: Status; search: string };
    strings: PaginatedResource<StringRow> | null;
}

const statusLabels: Record<Status, string> = {
    all: 'All',
    missing: 'Not translated',
    machine: 'By the AI model',
    manual: 'Written by people',
    unconfirmed: 'Waiting for a second visitor',
};

export default function Languages({ settings, languages, providers, maxDailyLimit, machine, progress, unconfirmed, filters, strings }: LanguagesProps) {
    const form = useForm({
        enabled: settings.enabled,
        languages: settings.languages,
        provider: settings.provider ?? '',
        daily_limit: String(settings.daily_limit),
    });
    const { errors: pageErrors } = usePage<{ errors: Record<string, string> }>().props;

    function save(event: FormEvent) {
        event.preventDefault();
        form.transform((data) => ({ ...data, provider: data.provider || null, daily_limit: Number(data.daily_limit) }));
        form.put('/admin/languages', { preserveScroll: true });
    }

    function toggleLanguage(code: string, checked: boolean) {
        form.setData('languages', checked ? [...form.data.languages, code] : form.data.languages.filter((selected) => selected !== code));
    }

    function filter(next: Partial<LanguagesProps['filters']>) {
        router.get('/admin/languages', { ...filters, ...next }, { preserveScroll: true, preserveState: true, replace: true });
    }

    return (
        <AdminLayout title="Languages">
            <Panel title="Site languages" description="Visitors get a language menu on public pages: the landing page, blog, forms, campaign pages and legal pages. Signed-in pages stay in English.">
                <form onSubmit={save} className="flex flex-col gap-5">
                    <Checkbox label="Translation is on" name="translation-enabled" checked={form.data.enabled}
                        description="Switch off to remove the language menu. Translations are kept."
                        onChange={(enabled) => form.setData('enabled', enabled)} />
                    <fieldset className="flex flex-col gap-2">
                        <legend className="mb-1 text-sm font-medium text-slate-200">Languages to offer</legend>
                        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            {languages.map((language) => (
                                <label key={language.code} htmlFor={`language-${language.code}`} className="flex cursor-pointer items-center gap-2 text-sm text-slate-200">
                                    <input id={`language-${language.code}`} type="checkbox" checked={form.data.languages.includes(language.code)}
                                        onChange={(event) => toggleLanguage(language.code, event.target.checked)} className="size-4 accent-emerald-500" />
                                    {language.name} <span lang={language.code} className="text-slate-400">({language.native})</span>
                                </label>
                            ))}
                        </div>
                        {form.errors.languages && <p className="text-sm text-rose-400">{form.errors.languages}</p>}
                    </fieldset>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="flex flex-col gap-1.5">
                            <label htmlFor="translation-provider" className="text-sm font-medium text-slate-200">Automatic translation</label>
                            <select id="translation-provider" value={form.data.provider} onChange={(event) => form.setData('provider', event.target.value)}
                                className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100">
                                <option value="">Off: people write every translation</option>
                                {providers.map((provider) => <option key={provider.value} value={provider.value}>{provider.label}</option>)}
                            </select>
                            {form.errors.provider
                                ? <p className="text-sm text-rose-400">{form.errors.provider}</p>
                                : <p className="text-xs text-slate-400">{providers.length === 0 ? 'Switch on an AI model in Admin → Integrations → AI models to translate automatically.' : 'Only page text is sent, never anyone’s personal details.'}</p>}
                        </div>
                        <TextField label="Most texts translated per day" name="translation-daily-limit" type="number" min={0} max={maxDailyLimit} value={form.data.daily_limit}
                            hint="Caps what the AI model costs. A typical page has 30–100 texts per language."
                            onChange={(value) => form.setData('daily_limit', value)} error={form.errors.daily_limit} />
                    </div>
                    <Button disabled={form.processing} className="self-start">{form.processing ? 'Saving…' : 'Save languages'}</Button>
                </form>
            </Panel>

            {settings.languages.length > 0 && (
                <Panel title="Progress" description={`Texts appear here as visitors open pages in another language. A text is translated once a second visitor has seen it, so made-up text never is.${unconfirmed > 0 ? ` ${unconfirmed} waiting for a second visitor.` : ''}`}>
                    <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {progress.map((language) => {
                            const percent = language.total === 0 ? 0 : Math.round((language.translated / language.total) * 100);

                            return (
                                <li key={language.code} className="flex flex-col gap-2 rounded-xl border border-slate-800 p-4">
                                    <div className="flex items-baseline justify-between">
                                        <span className="font-medium text-slate-100">{language.name}</span>
                                        <span className="text-sm tabular-nums text-slate-300">{percent}%</span>
                                    </div>
                                    <div className="h-2 overflow-hidden rounded-full bg-slate-800" role="progressbar" aria-label={`${language.name} translated`} aria-valuenow={percent} aria-valuemin={0} aria-valuemax={100}>
                                        <div className="h-full rounded-full bg-emerald-500" style={{ width: `${percent}%` }} />
                                    </div>
                                    <p className="text-xs text-slate-400">{language.translated} of {language.total} texts · {language.manual} written by people</p>
                                </li>
                            );
                        })}
                    </ul>
                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="button" variant="secondary" disabled={!machine.available} onClick={() => router.post('/admin/languages/translate', {}, { preserveScroll: true })}>
                            Translate now
                        </Button>
                        <span className="text-xs text-slate-400">
                            {machine.available
                                ? `Runs every 10 minutes by itself. ${machine.remaining_today.toLocaleString()} texts left today.`
                                : 'Choose an AI model above (and switch translation on) to translate automatically.'}
                        </span>
                    </div>
                    <StatusMessage tone="error" message={pageErrors.provider} />
                </Panel>
            )}

            {strings && filters.locale && (
                <Panel title="Translations" description="Correct any translation; your wording is kept and never replaced by the AI model. Clear it to let the AI model try again.">
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="flex flex-col gap-1.5">
                            <label htmlFor="filter-locale" className="text-sm font-medium text-slate-200">Language</label>
                            <select id="filter-locale" value={filters.locale} onChange={(event) => filter({ locale: event.target.value })}
                                className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100">
                                {progress.map((language) => <option key={language.code} value={language.code}>{language.name}</option>)}
                            </select>
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <label htmlFor="filter-status" className="text-sm font-medium text-slate-200">Show</label>
                            <select id="filter-status" value={filters.status} onChange={(event) => filter({ status: event.target.value as Status })}
                                className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100">
                                {(Object.keys(statusLabels) as Status[]).map((status) => <option key={status} value={status}>{statusLabels[status]}</option>)}
                            </select>
                        </div>
                        <SearchBox initial={filters.search} onSearch={(search) => filter({ search })} />
                    </div>

                    {strings.data.length === 0 ? (
                        <EmptyState title="Nothing here yet" description="Open your public pages in this language (use the language menu at the top) and the texts appear here." />
                    ) : (
                        <ul className="flex flex-col divide-y divide-slate-800">
                            {strings.data.map((row) => <StringEditor key={`${filters.locale}-${row.id}`} row={row} locale={filters.locale as string} />)}
                        </ul>
                    )}
                    {strings.meta.last_page > 1 && (
                        <nav aria-label="Pages" className="flex items-center justify-between text-sm">
                            {strings.links.prev ? <Link href={strings.links.prev} preserveScroll className="text-slate-300 hover:text-emerald-300">← Previous</Link> : <span />}
                            <span className="text-slate-400">Page {strings.meta.current_page} of {strings.meta.last_page}</span>
                            {strings.links.next ? <Link href={strings.links.next} preserveScroll className="text-slate-300 hover:text-emerald-300">Next →</Link> : <span />}
                        </nav>
                    )}
                </Panel>
            )}
        </AdminLayout>
    );
}

function SearchBox({ initial, onSearch }: { initial: string; onSearch: (search: string) => void }) {
    const [search, setSearch] = useState(initial);

    return (
        <form role="search" onSubmit={(event) => { event.preventDefault(); onSearch(search); }} className="flex min-w-56 flex-1 items-end gap-2">
            <div className="flex-1">
                <TextField label="Search" name="filter-search" type="search" value={search} onChange={setSearch} maxLength={200} />
            </div>
            <Button variant="secondary">Search</Button>
        </form>
    );
}

function StringEditor({ row, locale }: { row: StringRow; locale: string }) {
    const form = useForm({ locale, text: row.translation ?? '' });

    function save(event: FormEvent) {
        event.preventDefault();
        form.put(`/admin/languages/strings/${row.id}`, { preserveScroll: true, preserveState: true });
    }

    return (
        <li className="grid gap-3 py-4 lg:grid-cols-2">
            <div className="flex flex-col gap-1">
                <p className="text-sm text-slate-100">{row.text}</p>
                <p className="text-xs text-slate-500">
                    {!row.confirmed ? 'Seen by one visitor so far' : row.translation === null ? 'Not translated yet' : row.is_manual ? 'Written by a person' : 'Translated by the AI model'}
                </p>
            </div>
            <form onSubmit={save} className="flex flex-col gap-2">
                <label htmlFor={`translation-${row.id}`} className="sr-only">Translation of “{row.text}”</label>
                <textarea id={`translation-${row.id}`} lang={locale} rows={Math.min(4, Math.max(1, Math.ceil(row.text.length / 60)))} value={form.data.text} maxLength={2000}
                    onChange={(event) => form.setData('text', event.target.value)} aria-invalid={form.errors.text ? true : undefined}
                    className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 focus:border-emerald-500 focus:outline-none" />
                {form.errors.text && <p className="text-sm text-rose-400">{form.errors.text}</p>}
                <div className="flex gap-3 text-sm">
                    <button type="submit" disabled={form.processing || !form.isDirty} className="font-semibold text-emerald-400 hover:text-emerald-300 disabled:opacity-40">Save</button>
                    <button type="button" onClick={() => window.confirm('Remove this text and its translations? It comes back if a page still shows it.') && router.delete(`/admin/languages/strings/${row.id}`, { preserveScroll: true })}
                        className="text-slate-400 hover:text-rose-300">Remove text</button>
                </div>
            </form>
        </li>
    );
}
