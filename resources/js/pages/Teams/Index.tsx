import type { FormEvent } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import Button from '../../components/ui/Button';
import Panel from '../../components/ui/Panel';
import StatusMessage from '../../components/ui/StatusMessage';
import TextField from '../../components/ui/TextField';
import type { SharedPageProps } from '../../types';

interface TeamListItem {
    id: number;
    name: string;
    description: string | null;
    current_user: {
        role: 'owner' | 'admin' | 'member' | null;
        is_current_team: boolean;
    };
}

interface TeamsIndexProps {
    teams: TeamListItem[];
}

export default function TeamsIndex({ teams }: TeamsIndexProps) {
    const { errors } = usePage<SharedPageProps>().props;
    const { data, setData, post, processing, errors: formErrors, reset } = useForm({
        name: '',
        description: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post('/teams', { onSuccess: () => reset() });
    }

    return (
        <AppLayout title="Teams">
            <h1 className="text-2xl font-bold">Teams</h1>
            <StatusMessage tone="error" message={errors.invitation} />

            <Panel title="Your teams">
                {teams.length === 0 ? (
                    <p className="text-sm text-slate-400">You are not on any team yet. Create one below or accept an invitation.</p>
                ) : (
                    <ul className="flex flex-col divide-y divide-slate-800">
                        {teams.map((team) => (
                            <li key={team.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div className="flex flex-col">
                                    <Link href={`/teams/${team.id}`} className="font-medium text-slate-100 hover:text-emerald-300">
                                        {team.name}
                                    </Link>
                                    {team.description && <span className="text-sm text-slate-400">{team.description}</span>}
                                </div>
                                <div className="flex items-center gap-3 text-sm">
                                    {team.current_user.is_current_team && <span className="text-emerald-400">Current</span>}
                                    <span className="rounded-full border border-slate-700 px-2 py-0.5 capitalize text-slate-300">
                                        {team.current_user.role}
                                    </span>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            <Panel title="Create a team" description="You will be the owner and can invite people by email.">
                <form onSubmit={submit} className="flex max-w-md flex-col gap-4">
                    <TextField
                        label="Team name"
                        name="name"
                        required
                        maxLength={100}
                        value={data.name}
                        onChange={(value) => setData('name', value)}
                        error={formErrors.name}
                    />
                    <TextField
                        label="Description (optional)"
                        name="description"
                        maxLength={500}
                        value={data.description}
                        onChange={(value) => setData('description', value)}
                        error={formErrors.description}
                    />
                    <Button disabled={processing} className="self-start">Create team</Button>
                </form>
            </Panel>
        </AppLayout>
    );
}
