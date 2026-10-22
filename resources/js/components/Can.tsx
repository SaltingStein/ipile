import type { ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import type { SharedPageProps } from '../types';

/**
 * Conditionally renders child content when the current user holds a specific permission.
 */
interface CanProps {
    perform: string;
    children: ReactNode;
}

export default function Can({ perform, children }: CanProps) {
    const { auth } = usePage<SharedPageProps>().props;
    const permissions = Array.isArray(auth?.user?.permissions) ? auth.user.permissions : [];

    // Super admins pass every check on the server (Gate::before), so they hold no direct permissions to list here.
    const hasPermission = Boolean(auth?.user?.is_super_admin) || permissions.includes(perform);

    if (!hasPermission) {
        return null;
    }

    return <>{children}</>;
}
