import type { OAuthProviderOption } from '../types';

/**
 * "Continue with Google" style buttons for every provider enabled in the admin dashboard.
 * These are full-page links because the provider's consent screen is on another domain.
 */
interface OAuthButtonsProps {
    providers: OAuthProviderOption[];
}

export default function OAuthButtons({ providers }: OAuthButtonsProps) {
    if (providers.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-3">
            {providers.map((provider) => (
                <a
                    key={provider.provider}
                    href={`/auth/${provider.provider}/redirect`}
                    className="inline-flex items-center justify-center rounded-lg border border-slate-700 px-4 py-2 text-sm font-semibold text-slate-100 transition hover:border-slate-500"
                >
                    Continue with {provider.label}
                </a>
            ))}
            <div className="flex items-center gap-3 text-xs uppercase tracking-widest text-slate-500">
                <span className="h-px flex-1 bg-slate-800" />
                or
                <span className="h-px flex-1 bg-slate-800" />
            </div>
        </div>
    );
}
