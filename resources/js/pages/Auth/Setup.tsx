import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import AuthLayout from '../../components/AuthLayout';
import Button from '../../components/ui/Button';
import TextField from '../../components/ui/TextField';

export default function Setup() {
    const { data, setData, post, processing, errors, reset } = useForm({
        setup_key: '',
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post('/setup', { onFinish: () => reset('password', 'password_confirmation') });
    }

    return (
        <AuthLayout title="Set up your site" description="Create the super admin account. It can manage every setting, role and integration.">
            <p className="rounded-lg border border-slate-700 bg-slate-950/60 px-4 py-3 text-sm text-slate-300">
                The setup key is in the server log (<code className="text-emerald-300">storage/logs</code>). You can also run{' '}
                <code className="text-emerald-300">php artisan ipile:setup-key</code> on the server for a new one.
            </p>
            <form onSubmit={submit} className="flex flex-col gap-4">
                <TextField
                    label="Setup key"
                    name="setup_key"
                    autoComplete="off"
                    autoFocus
                    required
                    spellCheck={false}
                    value={data.setup_key}
                    onChange={(value) => setData('setup_key', value)}
                    error={errors.setup_key}
                />
                <TextField
                    label="Name"
                    name="name"
                    autoComplete="name"
                    required
                    value={data.name}
                    onChange={(value) => setData('name', value)}
                    error={errors.name}
                />
                <TextField
                    label="Email"
                    name="email"
                    type="email"
                    autoComplete="username"
                    required
                    value={data.email}
                    onChange={(value) => setData('email', value)}
                    error={errors.email}
                />
                <TextField
                    label="Password"
                    name="password"
                    type="password"
                    autoComplete="new-password"
                    required
                    hint="At least 8 characters, with letters and numbers."
                    value={data.password}
                    onChange={(value) => setData('password', value)}
                    error={errors.password}
                />
                <TextField
                    label="Confirm password"
                    name="password_confirmation"
                    type="password"
                    autoComplete="new-password"
                    required
                    value={data.password_confirmation}
                    onChange={(value) => setData('password_confirmation', value)}
                    error={errors.password_confirmation}
                />
                <Button disabled={processing}>Create super admin</Button>
            </form>
        </AuthLayout>
    );
}
