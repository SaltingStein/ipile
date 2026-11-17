import { Link, usePage } from '@inertiajs/react';
import type { SharedPageProps } from '../types';

/**
 * The home page's main buttons: the dashboard for signed-in people, otherwise "Create account" (while registration is
 * open) and "Sign in". Styling is passed in so each landing layout keeps its own look.
 */
interface LandingAuthLinksProps {
    dashboardLabel: string;
    primaryClassName: string;
    secondaryClassName: string;
}

export default function LandingAuthLinks({ dashboardLabel, primaryClassName, secondaryClassName }: LandingAuthLinksProps) {
    const { auth, canRegister } = usePage<SharedPageProps>().props;

    if (auth.user) {
        return <Link href="/dashboard" className={primaryClassName}>{dashboardLabel}</Link>;
    }

    return (
        <>
            {canRegister && <Link href="/register" className={primaryClassName}>Create account</Link>}
            <Link href="/login" className={canRegister ? secondaryClassName : primaryClassName}>Sign in</Link>
        </>
    );
}
