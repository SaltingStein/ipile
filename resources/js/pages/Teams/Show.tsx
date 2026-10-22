import { useState, type FormEvent } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import Button from '../../components/ui/Button';
import Panel from '../../components/ui/Panel';
import StatusMessage from '../../components/ui/StatusMessage';
import TextField from '../../components/ui/TextField';
import type { SharedPageProps } from '../../types';

interface Person {
    id: number;
    name: string;
    email: string | null;
}

interface Member extends Person {
    role: string;
}

interface Invitation {
    id: number;
    email: string;
    role: string;
}

interface RoleOption {
    value: string;
    label: string;
    description: string;
}

interface TeamDetails {
    id: number;
    name: string;
    description: string | null;
    owner: Person;
    members: Member[];
    invitations?: Invitation[];
    current_user: {
        role: 'owner' | 'admin' | 'member' | null;
        is_owner: boolean;
        can_manage: boolean;
        is_current_team: boolean;
    };
}

interface TeamShowProps {
    team: TeamDetails;
    roles: RoleOption[];
}

const keepScroll = { preserveScroll: true };

export default function TeamShow({ team, roles }: TeamShowProps) {
    const { auth, errors } = usePage<SharedPageProps>().props;
    const currentUserId = auth.user?.id;

    return (
        <AppLayout title={team.name}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-bold">{team.name}</h1>
                {!team.current_user.is_current_team && (
                    <Button type="button" variant="secondary" onClick={() => router.put('/current-team', { team_id: team.id }, keepScroll)}>
                        Switch to this team
                    </Button>
                )}
            </div>
            <StatusMessage tone="error" message={errors.member ?? errors.role ?? errors.user_id} />

            {team.current_user.can_manage ? <TeamDetailsForm team={team} /> : team.description && (
                <p className="text-slate-300">{team.description}</p>
            )}

            <Panel title="Members">
                <ul className="flex flex-col divide-y divide-slate-800">
                    <li className="flex flex-wrap items-center justify-between gap-3 py-3">
                        <PersonLabel person={team.owner} />
                        <span className="rounded-full border border-emerald-800 px-2 py-0.5 text-sm text-emerald-300">Owner</span>
                    </li>
                    {team.members.map((member) => (
                        <li key={member.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                            <PersonLabel person={member} />
                            <div className="flex items-center gap-3">
                                {team.current_user.can_manage ? (
                                    <select
                                        aria-label={`Role for ${member.name}`}
                                        value={member.role}
                                        onChange={(event) => router.put(`/teams/${team.id}/members/${member.id}`, { role: event.target.value }, keepScroll)}
                                        className="rounded-lg border border-slate-700 bg-slate-950 px-2 py-1 text-sm"
                                    >
                                        {roles.map((role) => (
                                            <option key={role.value} value={role.value}>{role.label}</option>
                                        ))}
                                    </select>
                                ) : (
                                    <span className="rounded-full border border-slate-700 px-2 py-0.5 text-sm capitalize text-slate-300">{member.role}</span>
                                )}
                                {member.id === currentUserId ? (
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={() => confirm('Leave this team?') && router.delete(`/teams/${team.id}/members/${member.id}`)}
                                    >
                                        Leave
                                    </Button>
                                ) : team.current_user.can_manage && (
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={() => confirm(`Remove ${member.name} from the team?`) && router.delete(`/teams/${team.id}/members/${member.id}`, keepScroll)}
                                    >
                                        Remove
                                    </Button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            </Panel>

            {team.current_user.can_manage && <InvitePanel team={team} roles={roles} />}
            {team.current_user.is_owner && <OwnerPanel team={team} />}
        </AppLayout>
    );
}

function PersonLabel({ person }: { person: Person }) {
    return (
        <div className="flex flex-col">
            <span className="font-medium">{person.name}</span>
            {person.email && <span className="text-sm text-slate-400">{person.email}</span>}
        </div>
    );
}

function TeamDetailsForm({ team }: { team: TeamDetails }) {
    const { data, setData, put, processing, errors } = useForm({
        name: team.name,
        description: team.description ?? '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        put(`/teams/${team.id}`, keepScroll);
    }

    return (
        <Panel title="Team details">
            <form onSubmit={submit} className="flex max-w-md flex-col gap-4">
                <TextField label="Team name" name="name" required maxLength={100} value={data.name} onChange={(value) => setData('name', value)} error={errors.name} />
                <TextField
                    label="Description (optional)"
                    name="description"
                    maxLength={500}
                    value={data.description}
                    onChange={(value) => setData('description', value)}
                    error={errors.description}
                />
                <Button disabled={processing} className="self-start">Save</Button>
            </form>
        </Panel>
    );
}

function InvitePanel({ team, roles }: { team: TeamDetails; roles: RoleOption[] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        role: roles[roles.length - 1]?.value ?? 'member',
    });
    const selectedRole = roles.find((role) => role.value === data.role);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post(`/teams/${team.id}/invitations`, { ...keepScroll, onSuccess: () => reset('email') });
    }

    return (
        <Panel title="Invite people" description="They will get an email with a link to join. The link works for 7 days.">
            <form onSubmit={submit} className="flex max-w-md flex-col gap-4">
                <TextField label="Email" name="email" type="email" required value={data.email} onChange={(value) => setData('email', value)} error={errors.email} />
                <label htmlFor="invite_role" className="flex flex-col gap-1.5 text-sm font-medium text-slate-200">
                    Role
                    <select
                        id="invite_role"
                        value={data.role}
                        onChange={(event) => setData('role', event.target.value)}
                        className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-normal text-slate-100"
                    >
                        {roles.map((role) => (
                            <option key={role.value} value={role.value}>{role.label}</option>
                        ))}
                    </select>
                    {selectedRole && <span className="text-xs font-normal text-slate-400">{selectedRole.description}</span>}
                </label>
                <Button disabled={processing} className="self-start">Send invitation</Button>
            </form>

            {team.invitations && team.invitations.length > 0 && (
                <div className="flex flex-col gap-2">
                    <h3 className="text-sm font-semibold text-slate-200">Pending invitations</h3>
                    <ul className="flex flex-col divide-y divide-slate-800">
                        {team.invitations.map((invitation) => (
                            <li key={invitation.id} className="flex flex-wrap items-center justify-between gap-3 py-2 text-sm">
                                <span>
                                    {invitation.email} <span className="capitalize text-slate-400">({invitation.role})</span>
                                </span>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => router.delete(`/teams/${team.id}/invitations/${invitation.id}`, keepScroll)}
                                >
                                    Cancel
                                </Button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </Panel>
    );
}

function OwnerPanel({ team }: { team: TeamDetails }) {
    const [newOwnerId, setNewOwnerId] = useState('');

    function transferOwnership() {
        const newOwner = team.members.find((member) => String(member.id) === newOwnerId);

        if (newOwner && confirm(`Make ${newOwner.name} the owner? You will stay on the team as an admin.`)) {
            router.put(`/teams/${team.id}/owner`, { user_id: newOwner.id }, keepScroll);
        }
    }

    function deleteTeam() {
        if (confirm(`Delete ${team.name}? Members lose access and this cannot be undone.`)) {
            router.delete(`/teams/${team.id}`);
        }
    }

    return (
        <Panel title="Owner actions">
            {team.members.length > 0 && (
                <div className="flex max-w-md flex-wrap items-end gap-3">
                    <label htmlFor="new_owner" className="flex flex-1 flex-col gap-1.5 text-sm font-medium text-slate-200">
                        Transfer ownership to
                        <select
                            id="new_owner"
                            value={newOwnerId}
                            onChange={(event) => setNewOwnerId(event.target.value)}
                            className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-normal text-slate-100"
                        >
                            <option value="">Choose a member</option>
                            {team.members.map((member) => (
                                <option key={member.id} value={member.id}>{member.name}</option>
                            ))}
                        </select>
                    </label>
                    <Button type="button" variant="secondary" disabled={newOwnerId === ''} onClick={transferOwnership}>
                        Transfer
                    </Button>
                </div>
            )}
            <div className="flex flex-col gap-2">
                <p className="text-sm text-slate-400">Deleting the team removes it for everyone.</p>
                <Button type="button" variant="danger" className="self-start" onClick={deleteTeam}>
                    Delete team
                </Button>
            </div>
        </Panel>
    );
}
