import { useState, type FormEvent } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import AuthLayout from '../../components/AuthLayout';
import Button from '../../components/ui/Button';
import TextField from '../../components/ui/TextField';
import type { SharedPageProps } from '../../types';

export default function ConfirmTwoFactor() {
    const { status } = usePage<SharedPageProps>().props;
    const [usingRecoveryCode, setUsingRecoveryCode] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        code: '',
        recovery_code: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post('/security/confirm', {
            onFinish: () => {
                reset('code', 'recovery_code');
            },
        });
    }

    function toggleMode() {
        setUsingRecoveryCode(!usingRecoveryCode);
        reset('code', 'recovery_code');
    }

    return (
        <AuthLayout
            title="Confirm it's you"
            description={status ?? 'This action needs a fresh code from your authenticator app.'}
        >
            <form onSubmit={submit} className="flex flex-col gap-4">
                {usingRecoveryCode ? (
                    <TextField
                        label="Recovery code"
                        name="recovery_code"
                        autoComplete="one-time-code"
                        autoFocus
                        required
                        value={data.recovery_code}
                        onChange={(value) => setData('recovery_code', value)}
                        error={errors.recovery_code}
                    />
                ) : (
                    <TextField
                        label="Authentication code"
                        name="code"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        pattern="[0-9]{6}"
                        maxLength={6}
                        autoFocus
                        required
                        value={data.code}
                        onChange={(value) => setData('code', value)}
                        error={errors.code}
                    />
                )}
                <Button disabled={processing}>Confirm</Button>
            </form>
            <button type="button" onClick={toggleMode} className="text-sm text-emerald-400 hover:text-emerald-300">
                {usingRecoveryCode ? 'Use an authentication code' : 'Use a recovery code'}
            </button>
        </AuthLayout>
    );
}
