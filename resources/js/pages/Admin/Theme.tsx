import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '../../components/AdminLayout';
import Button from '../../components/ui/Button';
import Panel from '../../components/ui/Panel';

interface ThemeOption {
    value: string;
    label: string;
    description: string;
    landing: string;
    /** Background, surface, text and accent colours. */
    swatches: [string, string, string, string];
}

interface ThemeProps {
    currentTheme: string;
    themes: ThemeOption[];
    updateUrl: string;
}

export default function Theme({ currentTheme, themes, updateUrl }: ThemeProps) {
    const { data, setData, put, processing, errors } = useForm({ theme: currentTheme });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        put(updateUrl, { preserveScroll: true });
    }

    return (
        <AdminLayout title="Theme">
            <Panel title="Site theme" description="Sets the colours of every page (public site, dashboard and admin) and the layout of your home page. Changes go live as soon as you save.">
                <form onSubmit={submit} className="flex flex-col gap-5">
                    <fieldset className="grid gap-3 sm:grid-cols-3">
                        <legend className="sr-only">Theme</legend>
                        {themes.map((theme) => (
                            <label key={theme.value}
                                className={`flex cursor-pointer flex-col gap-3 rounded-xl border p-4 transition has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-emerald-400 ${data.theme === theme.value ? 'border-emerald-500 bg-emerald-500/10' : 'border-slate-800 bg-slate-950/60 hover:border-slate-600'}`}>
                                <ThemePreview swatches={theme.swatches} />
                                <span className="flex items-center gap-2">
                                    <input type="radio" name="theme" value={theme.value} checked={data.theme === theme.value}
                                        onChange={() => setData('theme', theme.value)} className="accent-emerald-500" />
                                    <span className="font-semibold text-slate-50">{theme.label}</span>
                                </span>
                                <span className="text-sm text-slate-400">{theme.description}</span>
                            </label>
                        ))}
                    </fieldset>
                    {errors.theme && <p role="alert" className="text-sm text-rose-400">{errors.theme}</p>}
                    <div className="flex flex-wrap items-center gap-4">
                        <Button disabled={processing}>Save theme</Button>
                        <a href="/" target="_blank" rel="noreferrer" className="text-sm text-emerald-400 hover:text-emerald-300">View home page</a>
                    </div>
                </form>
            </Panel>
        </AdminLayout>
    );
}

/**
 * A miniature page in the theme's own colours, independent of the theme currently applied to the dashboard.
 */
function ThemePreview({ swatches: [background, surface, text, accent] }: { swatches: ThemeOption['swatches'] }) {
    return (
        <span aria-hidden="true" className="block rounded-lg p-3" style={{ backgroundColor: background }}>
            <span className="block space-y-2 rounded-md p-3" style={{ backgroundColor: surface }}>
                <span className="block h-2 w-2/3 rounded-full" style={{ backgroundColor: text }} />
                <span className="block h-2 w-1/2 rounded-full opacity-50" style={{ backgroundColor: text }} />
                <span className="block h-4 w-16 rounded-full" style={{ backgroundColor: accent }} />
            </span>
        </span>
    );
}
