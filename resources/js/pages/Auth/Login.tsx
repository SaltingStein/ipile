import type { FormEvent } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import AuthLayout from '../../components/AuthLayout';
import OAuthButtons from '../../components/OAuthButtons';
import Button from '../../components/ui/Button';
import Checkbox from '../../components/ui/Checkbox';
import TextField from '../../components/ui/TextField';
import StatusMessage from '../../components/ui/StatusMessage';
import type { OAuthProviderOption, SharedPageProps } from '../../types';

interface LoginProps {
    canRegister: boolean;
    oauthProviders: OAuthProviderOption[];
    setupUrl: string | null;
    magicLinkEnabled: boolean;
}

export default function Login({ canRegister, oauthProviders, setupUrl, magicLinkEnabled }: LoginProps) {
    const { errors: pageErrors } = usePage<SharedPageProps>().props;
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post('/login', { onFinish: () => reset('password') });
    }

    return (
        <AuthLayout title="Sign in" description="Welcome back. Sign in to continue.">
            {setupUrl && (
                <p className="rounded-lg border border-amber-800 bg-amber-950/40 px-4 py-3 text-sm text-amber-200">
                    This site has no administrator yet.{' '}
                    <Link href={setupUrl} className="font-semibold underline">
                        Finish setup
                    </Link>
                </p>
            )}
            <OAuthButtons providers={oauthProviders} />
            <StatusMessage tone="error" message={pageErrors.magic_link} />
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
                <TextField
                    label="Password"
                    name="password"
                    type="password"
                    autoComplete="current-password"
                    required
                    value={data.password}
                    onChange={(value) => setData('password', value)}
                    error={errors.password}
                />
                <div className="flex items-center justify-between gap-4">
                    <Checkbox label="Remember me" name="remember" checked={data.remember} onChange={(checked) => setData('remember', checked)} />
                    <Link href="/forgot-password" className="text-sm text-emerald-400 hover:text-emerald-300">
                        Forgot password?
                    </Link>
                </div>
                <Button disabled={processing}>Sign in</Button>
            </form>
            {magicLinkEnabled && <MagicLinkForm />}
            {canRegister && (
                <p className="text-center text-sm text-slate-400">
                    New here?{' '}
                    <Link href="/register" className="font-semibold text-emerald-400 hover:text-emerald-300">
                        Create an account
                    </Link>
                </p>
            )}
        </AuthLayout>
    );
}

function MagicLinkForm() {
    const form = useForm({ email: '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/auth/magic-link', { preserveScroll: true });
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-4 border-t border-slate-800 pt-5">
            <h2 className="text-lg font-semibold text-slate-100">Sign in by email link</h2>
            <p className="text-sm text-slate-400">Use your existing account's email address. Your account's two-factor rules still apply.</p>
            <TextField label="Email for sign-in link" name="magic_link_email" type="email" autoComplete="email" required
                value={form.data.email} onChange={(email) => form.setData('email', email)} error={form.errors.email} />
            <Button disabled={form.processing}>{form.processing ? 'Sending…' : 'Email me a sign-in link'}</Button>
        </form>
    );
}
