import ConsentManager from './ConsentManager';
import { Link, usePage } from '@inertiajs/react';
import type { SharedPageProps } from '../types';

export default function BrandFooter({ className = '' }: { className?: string }) {
    const { branding, legalLinks, consent, aboutBoilerplateUrl, boilerplateCredit } = usePage<SharedPageProps>().props;

    return (
        <footer className={`flex flex-wrap items-center justify-center gap-x-3 gap-y-1 px-6 py-5 text-center text-xs text-slate-500 ${className}`}>
            <span className="break-words">{branding.name}</span>
            <span aria-label={`App version ${branding.version}`}>v{branding.version}</span>
            {boilerplateCredit && (aboutBoilerplateUrl
                ? <Link href={aboutBoilerplateUrl} className="underline hover:text-emerald-400">Built on {boilerplateCredit}</Link>
                : <span>Built on {boilerplateCredit}</span>)}
            {legalLinks.map((link) => <a key={link.href} href={link.href} className="underline hover:text-emerald-400">{link.title}</a>)}
            <button type="button" onClick={() => window.dispatchEvent(new Event('open-consent-preferences'))} className="underline hover:text-emerald-400">Cookie preferences</button>
            <ConsentManager key={consent.config.version + ':' + (consent.choice?.id ?? 'new')} />
        </footer>
    );
}
