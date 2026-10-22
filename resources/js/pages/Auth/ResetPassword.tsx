import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import AuthLayout from '../../components/AuthLayout';
import Button from '../../components/ui/Button';
import TextField from '../../components/ui/TextField';

interface ResetPasswordProps {
    email: string;
    token: string;
}

export default function ResetPassword({ email, token }: ResetPasswordProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post('/reset-password', { onFinish: () => reset('password', 'password_confirmation') });
    }

    return (
        <AuthLayout title="Choose a new password" description="You will be signed out of other devices.">
            <form onSubmit={submit} className="flex flex-col gap-4">
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
                    label="New password"
                    name="password"
                    type="password"
                    autoComplete="new-password"
                    autoFocus
                    required
                    hint="At least 8 characters, with letters and numbers."
                    value={data.password}
                    onChange={(value) => setData('password', value)}
                    error={errors.password}
                />
                <TextField
                    label="Confirm new password"
                    name="password_confirmation"
                    type="password"
                    autoComplete="new-password"
                    required
                    value={data.password_confirmation}
                    onChange={(value) => setData('password_confirmation', value)}
                    error={errors.password_confirmation}
                />
                <Button disabled={processing}>Reset password</Button>
            </form>
        </AuthLayout>
    );
}
