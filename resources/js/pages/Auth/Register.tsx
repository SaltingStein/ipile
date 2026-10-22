import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import AuthLayout from '../../components/AuthLayout';
import OAuthButtons from '../../components/OAuthButtons';
import FormFields from '../../components/forms/FormFields';
import Button from '../../components/ui/Button';
import TextField from '../../components/ui/TextField';
import type { FormAnswer, FormFieldDefinition, OAuthProviderOption } from '../../types';

interface RegisterProps {
    oauthProviders: OAuthProviderOption[];
    /** Extra questions from Admin → Forms → Sign-up questions (empty until published). */
    signupFields: FormFieldDefinition[];
}

export default function Register({ oauthProviders, signupFields }: RegisterProps) {
    const { data, setData, post, processing, errors, reset } = useForm<{
        name: string;
        email: string;
        password: string;
        password_confirmation: string;
        answers: Record<string, FormAnswer>;
    }>({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        answers: {},
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post('/register', { onFinish: () => reset('password', 'password_confirmation') });
    }

    return (
        <AuthLayout title="Create your account" description="If you started as a guest, your activity carries over.">
            <OAuthButtons providers={oauthProviders} />
            <form onSubmit={submit} className="flex flex-col gap-4">
                <TextField
                    label="Name"
                    name="name"
                    autoComplete="name"
                    autoFocus
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
                {signupFields.length > 0 && (
                    <FormFields fields={signupFields} answers={data.answers} idPrefix="signup"
                        errors={errors as Record<string, string | undefined>}
                        onChange={(key, value) => setData('answers', { ...data.answers, [key]: value })} />
                )}
                <Button disabled={processing}>Create account</Button>
            </form>
            <p className="text-center text-sm text-slate-400">
                Already registered?{' '}
                <Link href="/login" className="font-semibold text-emerald-400 hover:text-emerald-300">
                    Sign in
                </Link>
            </p>
        </AuthLayout>
    );
}
