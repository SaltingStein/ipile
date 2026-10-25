import type { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import AuthLayout from '../../components/AuthLayout';
import FormFields from '../../components/forms/FormFields';
import Button from '../../components/ui/Button';
import TextField from '../../components/ui/TextField';
import type { FormAnswer, FormFieldDefinition } from '../../types';

interface ProfileProps {
    name: string;
    email: string;
    signupFields: FormFieldDefinition[];
    submitUrl: string;
}

export default function CompleteOAuthProfile({ name, email, signupFields, submitUrl }: ProfileProps) {
    const form = useForm<{ name: string; answers: Record<string, FormAnswer> }>({ name, answers: {} });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(submitUrl);
    }

    return (
        <AuthLayout title="Complete your profile" description="Confirm your details to finish creating your account. Your connected sign-in has already been checked.">
            <form onSubmit={submit} className="flex flex-col gap-4">
                <TextField label="Name" name="name" autoComplete="name" autoFocus required value={form.data.name}
                    onChange={(value) => form.setData('name', value)} error={form.errors.name} />
                <TextField label="Connected email" name="email" type="email" value={email} onChange={() => undefined} readOnly
                    hint="This address comes from your connected account." />
                {signupFields.length > 0 && (
                    <FormFields fields={signupFields} answers={form.data.answers} idPrefix="social-signup"
                        errors={form.errors as Record<string, string | undefined>}
                        onChange={(key, value) => form.setData('answers', { ...form.data.answers, [key]: value })} />
                )}
                <Button disabled={form.processing}>Create account</Button>
            </form>
            <Link href="/login" className="text-center text-sm text-emerald-400 underline-offset-4 hover:underline">Back to sign in</Link>
        </AuthLayout>
    );
}
