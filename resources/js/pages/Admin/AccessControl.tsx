import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '../../components/AdminLayout';
import Button from '../../components/ui/Button';
import Checkbox from '../../components/ui/Checkbox';
import Panel from '../../components/ui/Panel';

interface RoleSummary {
    name: string;
    permissions: string[];
}

interface UserSummary {
    id: number;
    name: string;
    email: string | null;
    roles: string[];
}

interface AccessControlProps {
    roles: RoleSummary[];
    permissions: string[];
    users: UserSummary[];
}

const keepScroll = { preserveScroll: true };

export default function AccessControl({ roles, permissions, users }: AccessControlProps) {
    const [selectedRole, setSelectedRole] = useState<string>(roles[0]?.name ?? '');
    const currentRole = useMemo(
        () => roles.find((role) => role.name === selectedRole) ?? roles[0],
        [roles, selectedRole],
    );

    const userAssignmentForm = useForm({
        user_id: users[0]?.id ? String(users[0].id) : '',
        role: '',
        revoke_role: false,
    });

    const permissionForm = useForm({
        role: selectedRole,
        permission: '',
        revoke_permission: false,
    });

    const usersWithRolesCount = useMemo(
        () => users.filter((user) => user.roles.length > 0).length,
        [users],
    );

    const rolePermissionLinksCount = useMemo(
        () => roles.reduce((count, role) => count + role.permissions.length, 0),
        [roles],
    );

    function submitRoleAssignment(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        userAssignmentForm.post('/admin/access', {
            ...keepScroll,
            onSuccess: () => userAssignmentForm.reset('role', 'revoke_role'),
        });
    }

    function togglePermission(permission: string, checked: boolean) {
        permissionForm.setData('role', selectedRole);
        permissionForm.setData('permission', permission);
        permissionForm.setData('revoke_permission', !checked);

        permissionForm.post('/admin/access', {
            ...keepScroll,
            onSuccess: () => permissionForm.setData('permission', ''),
        });
    }

    return (
        <AdminLayout title="Roles & permissions">
            <div className="flex flex-col gap-6">
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <a
                        href="#assign-roles"
                        className="rounded-xl border border-slate-800 bg-slate-900/60 p-4 transition hover:border-emerald-500/50 hover:bg-slate-900"
                    >
                        <div className="flex items-start justify-between gap-2">
                            <h2 className="font-semibold text-slate-100">User access</h2>
                            <span className="rounded-full border border-emerald-700/60 bg-emerald-500/10 px-2.5 py-1 text-xs text-emerald-300">
                                {usersWithRolesCount}/{users.length}
                            </span>
                        </div>
                        <p className="mt-2 text-sm text-slate-400">
                            Assign or revoke roles for team members.
                        </p>
                        <p className="mt-4 text-xs font-medium text-emerald-400">Open settings →</p>
                    </a>

                    <a
                        href="#permission-map"
                        className="rounded-xl border border-slate-800 bg-slate-900/60 p-4 transition hover:border-emerald-500/50 hover:bg-slate-900"
                    >
                        <div className="flex items-start justify-between gap-2">
                            <h2 className="font-semibold text-slate-100">Permission map</h2>
                            <span className="rounded-full border border-emerald-700/60 bg-emerald-500/10 px-2.5 py-1 text-xs text-emerald-300">
                                {rolePermissionLinksCount}
                            </span>
                        </div>
                        <p className="mt-2 text-sm text-slate-400">
                            Manage permission toggles per role.
                        </p>
                        <p className="mt-4 text-xs font-medium text-emerald-400">Open settings →</p>
                    </a>

                    <a
                        href="#role-summary"
                        className="rounded-xl border border-slate-800 bg-slate-900/60 p-4 transition hover:border-emerald-500/50 hover:bg-slate-900"
                    >
                        <div className="flex items-start justify-between gap-2">
                            <h2 className="font-semibold text-slate-100">Role summary</h2>
                            <span className="rounded-full border border-emerald-700/60 bg-emerald-500/10 px-2.5 py-1 text-xs text-emerald-300">
                                {roles.length}
                            </span>
                        </div>
                        <p className="mt-2 text-sm text-slate-400">
                            Review role coverage and current assignments.
                        </p>
                        <p className="mt-4 text-xs font-medium text-emerald-400">Open summary →</p>
                    </a>
                </div>

                <Panel title="Assign roles to users" description="Update the role attached to a user without leaving the dashboard.">
                    <div id="assign-roles" className="sr-only">
                        Assign roles section
                    </div>
                    <form onSubmit={submitRoleAssignment} className="flex max-w-2xl flex-col gap-5">
                        <div className="grid gap-4 md:grid-cols-2">
                            <label className="flex flex-col gap-2 text-sm text-slate-200">
                                User
                                <select
                                    name="user_id"
                                    value={userAssignmentForm.data.user_id}
                                    onChange={(event) => userAssignmentForm.setData('user_id', event.target.value)}
                                    className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100"
                                >
                                    {users.map((user) => (
                                        <option key={user.id} value={String(user.id)}>
                                            {user.name} {user.email ? `(${user.email})` : ''}
                                        </option>
                                    ))}
                                </select>
                            </label>

                            <label className="flex flex-col gap-2 text-sm text-slate-200">
                                Role
                                <select
                                    name="role"
                                    value={userAssignmentForm.data.role}
                                    onChange={(event) => userAssignmentForm.setData('role', event.target.value)}
                                    className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100"
                                >
                                    <option value="">Select role</option>
                                    {roles.map((role) => (
                                        <option key={role.name} value={role.name}>
                                            {role.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>

                        <Checkbox
                            label="Revoke this role instead of assigning it"
                            name="revoke_role"
                            checked={userAssignmentForm.data.revoke_role}
                            onChange={(checked) => userAssignmentForm.setData('revoke_role', checked)}
                        />

                        {userAssignmentForm.errors.role && (<p className="text-sm text-rose-400">{userAssignmentForm.errors.role}</p>)}

                        <Button disabled={userAssignmentForm.processing} className="self-start">
                            Save user access
                        </Button>
                    </form>
                </Panel>

                <Panel title="Permission map" description="Turn permissions on and off for a role from one place.">
                    <div id="permission-map" className="sr-only">
                        Permission map section
                    </div>
                    <div className="flex flex-col gap-4">
                        <label className="flex flex-col gap-2 text-sm text-slate-200">
                            Role
                            <select
                                value={selectedRole}
                                onChange={(event) => {
                                    setSelectedRole(event.target.value);
                                    permissionForm.setData('role', event.target.value);
                                }}
                                className="max-w-sm rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100"
                            >
                                {roles.map((role) => (
                                    <option key={role.name} value={role.name}>
                                        {role.name}
                                    </option>
                                ))}
                            </select>
                        </label>

                        <div className="grid gap-3 md:grid-cols-2">
                            {permissions.map((permission) => (
                                <Checkbox
                                    key={permission}
                                    label={permission}
                                    name={`permission_${permission}`}
                                    checked={currentRole?.permissions.includes(permission) ?? false}
                                    onChange={(checked) => togglePermission(permission, checked)}
                                />
                            ))}
                        </div>
                    </div>
                </Panel>

                <Panel title="Current role summary" description="Quick overview of who has which role.">
                    <div id="role-summary" className="sr-only">
                        Role summary section
                    </div>
                    <div className="grid gap-4 lg:grid-cols-2">
                        {roles.map((role) => (
                            <div key={role.name} className="rounded-xl border border-slate-800 bg-slate-950/80 p-4">
                                <h3 className="text-base font-semibold capitalize text-slate-50">{role.name}</h3>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    {role.permissions.length ? (
                                        role.permissions.map((permission) => (
                                            <span key={`${role.name}-${permission}`} className="rounded-full border border-slate-700 bg-slate-900 px-2.5 py-1 text-xs text-slate-300">
                                                {permission}
                                            </span>
                                        ))
                                    ) : (
                                        <span className="text-sm text-slate-500">No explicit permissions</span>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>

                    <div className="mt-6 space-y-3">
                        {users.map((user) => (
                            <div key={user.id} className="flex flex-col gap-1 rounded-xl border border-slate-800 bg-slate-950/60 px-4 py-3 md:flex-row md:items-center md:justify-between">
                                <div>
                                    <p className="font-medium text-slate-50">{user.name}</p>
                                    {user.email && <p className="text-sm text-slate-400">{user.email}</p>}
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {user.roles.length ? (
                                        user.roles.map((role) => (
                                            <span key={`${user.id}-${role}`} className="rounded-full border border-emerald-600/40 bg-emerald-500/10 px-2.5 py-1 text-xs text-emerald-200">
                                                {role}
                                            </span>
                                        ))
                                    ) : (
                                        <span className="text-sm text-slate-500">No roles</span>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                </Panel>
            </div>
        </AdminLayout>
    );
}
