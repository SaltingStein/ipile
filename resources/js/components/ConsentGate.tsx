import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { SharedPageProps } from '../types';

export type ConsentCategory = 'analytics' | 'marketing';

/** True while the category is offered and the visitor has a current choice allowing it. */
export function hasConsent(consent: SharedPageProps['consent'], category: ConsentCategory): boolean {
    return Boolean(consent.config[`${category}_enabled`] && consent.choice?.[category]);
}

/** Mount optional integrations only while their category has a current consent choice. */
export default function ConsentGate({ category, children }: { category: ConsentCategory; children: ReactNode }) {
    const { consent } = usePage<SharedPageProps>().props;
    return hasConsent(consent, category) ? <>{children}</> : null;
}
