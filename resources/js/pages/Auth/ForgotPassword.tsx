import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import AuthLayout from '../../components/AuthLayout';
import Button from '../../components/ui/Button';
import TextField from '../../components/ui/TextField';

export default function ForgotPassword() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post('/forgot-password');
    }

    return (
        <AuthLayout title="Reset your password" description="Enter your email and we will send you a link to choose a new password.">
            <form onSubmit={submit} className="flex flex-col gap-4">
                <TextField
                    label="Email"
                    name="email"
                    type="email"
                    autoComplete="username"
                    autoFocus
                    required
                    value={data.email}
                    onChange={(value) => setData('email', value)}
                    error={errors.email}
                />
                <Button disabled={processing}>Email reset link</Button>
            </form>
            <p className="text-center text-sm text-slate-400">
                <Link href="/login" className="font-semibold text-emerald-400 hover:text-emerald-300">
                    Back to sign in
                </Link>
            </p>
        </AuthLayout>
    );
}
