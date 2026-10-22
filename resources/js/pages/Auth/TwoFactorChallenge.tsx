import { useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import AuthLayout from '../../components/AuthLayout';
import Button from '../../components/ui/Button';
import Checkbox from '../../components/ui/Checkbox';
import TextField from '../../components/ui/TextField';

interface TwoFactorChallengeProps {
    trustDeviceDays: number;
}

export default function TwoFactorChallenge({ trustDeviceDays }: TwoFactorChallengeProps) {
    const [usingRecoveryCode, setUsingRecoveryCode] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        code: '',
        recovery_code: '',
        trust_device: false,
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post('/two-factor-challenge', { onFinish: () => reset('code', 'recovery_code') });
    }

    function toggleMode() {
        setUsingRecoveryCode(!usingRecoveryCode);
        reset('code', 'recovery_code');
    }

    return (
        <AuthLayout
            title="Two-factor authentication"
            description={usingRecoveryCode
                ? 'Enter one of your emergency recovery codes.'
                : 'Enter the 6-digit code from your authenticator app.'}
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
                {trustDeviceDays > 0 && (
                    <Checkbox
                        label={`Trust this device for ${trustDeviceDays} days`}
                        name="trust_device"
                        description="Only choose this on a private device."
                        checked={data.trust_device}
                        onChange={(checked) => setData('trust_device', checked)}
                    />
                )}
                <Button disabled={processing}>Verify</Button>
            </form>
            <button type="button" onClick={toggleMode} className="text-sm text-emerald-400 hover:text-emerald-300">
                {usingRecoveryCode ? 'Use an authentication code' : 'Use a recovery code'}
            </button>
        </AuthLayout>
    );
}
