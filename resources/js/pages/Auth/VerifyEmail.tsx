import type { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import AuthLayout from '../../components/AuthLayout';
import Button from '../../components/ui/Button';

export default function VerifyEmail() {
    const { post, processing } = useForm({});

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post('/email/verification-notification');
    }

    return (
        <AuthLayout
            title="Verify your email"
            description="We sent a verification link to your email address. Click it to finish setting up your account."
        >
            <form onSubmit={submit} className="flex flex-col gap-3">
                <Button disabled={processing}>Resend verification email</Button>
                <Button type="button" variant="secondary" onClick={() => router.post('/logout')}>
                    Log out
                </Button>
            </form>
        </AuthLayout>
    );
}
