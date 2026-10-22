import { usePage } from '@inertiajs/react';
import type { SharedPageProps } from '../types';

export default function AppBrand() {
    const { branding } = usePage<SharedPageProps>().props;

    return (
        <span className="inline-flex max-w-full items-center gap-3">
            {branding.logo_url && <img src={branding.logo_url} alt="" className="h-9 w-auto max-w-28 shrink-0 object-contain" />}
            <span className="break-words">{branding.name}</span>
        </span>
    );
}
