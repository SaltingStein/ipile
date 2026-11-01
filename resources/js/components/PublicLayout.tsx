import AppBrand from './AppBrand';
import ConsentManager from './ConsentManager';
import { useEffect, useId, useRef, useState } from 'react';
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
    pageNavigation?: { label: string; href: string }[];
    children: ReactNode;
}

const styles = {
    border: 'border-slate-800',
    muted: 'text-slate-500',
    link: 'text-slate-300 hover:text-slate-50',
    button: 'bg-emerald-500 text-slate-950 hover:bg-emerald-400',
};

export default function PublicLayout({ title, className = '', pageNavigation = [], children }: PublicLayoutProps) {
    const { auth, canRegister, siteMenu = [], siteLanguages = null } = usePage<SharedPageProps>().props;
    const root = useRef<HTMLDivElement>(null);
    const { language, setLanguage } = useSiteTranslation(root, siteLanguages);
    const { url } = usePage();
    const currentPath = url.split(/[?#]/)[0];
    const [menuOpen, setMenuOpen] = useState(false);
    const menuId = useId();
    const menuButton = useRef<HTMLButtonElement>(null);
    const focus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current';

    useEffect(() => {
        setMenuOpen(false);
    }, [url]);

    return (
        <div ref={root} className={`flex min-h-screen flex-col bg-slate-950 text-slate-100 antialiased ${className}`}>
            <Head title={title} />
            <header className={`border-b ${styles.border}`}>
                <nav aria-label="Site" className="mx-auto flex w-full max-w-6xl flex-wrap items-center gap-x-6 gap-y-3 px-6 py-4 text-sm"
                    onKeyDown={(event) => {
                        if (event.key === 'Escape' && menuOpen) {
                            setMenuOpen(false);
                            menuButton.current?.focus();
                        }
                    }}
                    onBlur={(event) => {
                        if (!event.currentTarget.contains(event.relatedTarget)) {
                            setMenuOpen(false);
                        }
                    }}>
                    <Link href="/" translate="no" className={`mr-auto font-semibold tracking-wide ${focus}`}><AppBrand /></Link>
                    <button ref={menuButton} type="button" aria-expanded={menuOpen} aria-controls={menuId}
                        aria-label={menuOpen ? 'Close main menu' : 'Open main menu'} onClick={() => setMenuOpen((open) => !open)}
                        className={`inline-flex min-h-11 items-center gap-2 rounded-lg border px-3 py-2 lg:hidden ${styles.border} ${focus}`}>
                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" className="size-5">
                            <path d={menuOpen ? 'm6 6 12 12M6 18 18 6' : 'M4 6h16M4 12h16M4 18h16'} />
                        </svg>
                        {menuOpen ? 'Close menu' : 'Menu'}
                    </button>
                    <div id={menuId} className={`${menuOpen ? 'flex' : 'hidden'} w-full flex-col items-stretch gap-2 border-t pt-3 lg:flex lg:w-auto lg:flex-1 lg:flex-row lg:flex-wrap lg:items-center lg:justify-end lg:gap-x-6 lg:gap-y-3 lg:border-0 lg:pt-0 ${styles.border}`}>
                        {pageNavigation.map((item) => (
                            <a key={item.href} href={item.href} onClick={() => setMenuOpen(false)}
                                className={`py-2 transition lg:py-0 ${styles.link} ${focus}`}>{item.label}</a>
                        ))}
                        {siteMenu.map((item) => {
                            const isCurrent = currentPath === item.href.split(/[?#]/)[0];

                            return (
                                <Link key={item.href} href={item.href} aria-current={isCurrent ? 'page' : undefined}
                                    onClick={() => setMenuOpen(false)}
                                    className={`py-2 transition lg:py-0 ${isCurrent ? 'font-semibold text-slate-50' : styles.link} ${focus}`}>
                                    {item.label}
                                </Link>
                            );
                        })}
                        {siteLanguages && <LanguageSwitcher languages={siteLanguages} value={language} onChange={setLanguage} className="py-2 lg:py-0" />}
                        {auth.user ? (
                            <Link href="/dashboard" onClick={() => setMenuOpen(false)} className={`rounded-full px-4 py-2 text-center font-medium transition ${styles.button} ${focus}`}>Dashboard</Link>
                        ) : (
                            <>
                                <Link href="/login" onClick={() => setMenuOpen(false)} className={`py-2 transition lg:py-0 ${styles.link} ${focus}`}>Sign in</Link>
                                {canRegister && (
                                    <Link href="/register" onClick={() => setMenuOpen(false)} className={`rounded-full px-4 py-2 text-center font-medium transition ${styles.button} ${focus}`}>Create account</Link>
                                )}
                            </>
                        )}
                    </div>
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
