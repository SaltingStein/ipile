import AppBrand from './AppBrand';
import ConsentManager from './ConsentManager';
import { useRef } from 'react';
import type { ReactNode } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import type { SharedPageProps } from '../types';
import LanguageSwitcher from './translation/LanguageSwitcher';
import { useSiteTranslation } from './translation/useSiteTranslation';

/**
 * Shell for public pages (landing layouts, legal pages): top navigation and the site footer.
 * Colours come from the site theme (see resources/css/app.css), so there are no per-page colour props.
 * While site translation is on (Admin → Languages), visitors can switch the page to another language.
 */
interface PublicLayoutProps {
    title: string;
    className?: string;
    children: ReactNode;
}

const styles = {
    border: 'border-slate-800',
    muted: 'text-slate-500',
    link: 'text-slate-300 hover:text-slate-50',
    button: 'bg-emerald-500 text-slate-950 hover:bg-emerald-400',
};

export default function PublicLayout({ title, className = '', children }: PublicLayoutProps) {
    const { auth, canRegister, siteMenu = [], siteLanguages = null } = usePage<SharedPageProps>().props;
    const root = useRef<HTMLDivElement>(null);
    const { language, setLanguage } = useSiteTranslation(root, siteLanguages);
    const currentPath = usePage().url.split(/[?#]/)[0];
    const focus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current';

    return (
        <div ref={root} className={`flex min-h-screen flex-col bg-slate-950 text-slate-100 antialiased ${className}`}>
            <Head title={title} />
            <header className={`border-b ${styles.border}`}>
                <nav aria-label="Site" className="mx-auto flex w-full max-w-6xl flex-wrap items-center gap-x-6 gap-y-3 px-6 py-4 text-sm">
                    <Link href="/" translate="no" className={`mr-auto font-semibold tracking-wide ${focus}`}><AppBrand /></Link>
                    {siteMenu.map((item) => {
                        const isCurrent = currentPath === item.href.split(/[?#]/)[0];

                        return (
                            <Link key={item.href} href={item.href} aria-current={isCurrent ? 'page' : undefined}
                                className={`transition ${isCurrent ? 'font-semibold text-slate-50' : styles.link} ${focus}`}>
                                {item.label}
                            </Link>
                        );
                    })}
                    {siteLanguages && <LanguageSwitcher languages={siteLanguages} value={language} onChange={setLanguage} />}
                    {auth.user ? (
                        <Link href="/dashboard" className={`rounded-full px-4 py-2 font-medium transition ${styles.button} ${focus}`}>Dashboard</Link>
                    ) : (
                        <>
                            <Link href="/login" className={`transition ${styles.link} ${focus}`}>Sign in</Link>
                            {canRegister && (
                                <Link href="/register" className={`rounded-full px-4 py-2 font-medium transition ${styles.button} ${focus}`}>Create account</Link>
                            )}
                        </>
                    )}
                </nav>
            </header>
            <div className="flex flex-1 flex-col">{children}</div>
            <SiteFooter focus={focus} />
        </div>
    );
}

function SiteFooter({ focus }: { focus: string }) {
    const { branding, legalLinks, consent, aboutBoilerplateUrl, boilerplateCredit } = usePage<SharedPageProps>().props;
    const today = new Date();
    const launchDate = parseDate(branding.launched_on);
    const firstYear = launchDate && launchDate.getFullYear() < today.getFullYear() ? `${launchDate.getFullYear()}–` : '';
    const formattedLaunch = launchDate?.toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' });

    return (
        <footer className={`border-t text-sm ${styles.border}`}>
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 px-6 py-8">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <Link href="/" className={`font-semibold ${focus}`}><AppBrand /></Link>
                    <nav aria-label="Legal" className="flex flex-wrap gap-x-5 gap-y-2">
                        {legalLinks.map((link) => (
                            <Link key={link.href} href={link.href} className={`transition ${styles.link} ${focus}`}>{link.title}</Link>
                        ))}
                        <button type="button" onClick={() => window.dispatchEvent(new Event('open-consent-preferences'))} className={`transition ${styles.link} ${focus}`}>
                            Cookie preferences
                        </button>
                    </nav>
                </div>
                <div className={`flex flex-wrap justify-between gap-x-6 gap-y-2 text-xs ${styles.muted}`}>
                    <p>
                        © {firstYear}{today.getFullYear()} {branding.organisation_name || branding.name}. All rights reserved.
                        {boilerplateCredit && <> · {aboutBoilerplateUrl
                            ? <Link href={aboutBoilerplateUrl} className={`underline-offset-4 transition hover:underline ${focus}`}>Built on {boilerplateCredit}</Link>
                            : <>Built on {boilerplateCredit}</>}</>}
                    </p>
                    {launchDate && (
                        <p>{launchDate > today ? 'Launching' : 'Live since'} <time dateTime={branding.launched_on ?? undefined}>{formattedLaunch}</time></p>
                    )}
                </div>
            </div>
            <ConsentManager key={consent.config.version + ':' + (consent.choice?.id ?? 'new')} />
        </footer>
    );
}

/**
 * Read a Y-m-d date as a local calendar date, so it does not shift a day in time zones behind UTC.
 */
function parseDate(value: string | null): Date | null {
    const [year, month, day] = value?.split('-').map(Number) ?? [];

    return year && month && day ? new Date(year, month - 1, day) : null;
}
