import { useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import AuthLayout from '../../components/AuthLayout';
import Button from '../../components/ui/Button';
import TextField from '../../components/ui/TextField';

interface ConfirmPasswordProps {
    requiresAuthenticator?: boolean;
}

export default function ConfirmPassword({ requiresAuthenticator = false }: ConfirmPasswordProps) {
    const [usingRecoveryCode, setUsingRecoveryCode] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        password: '',
        code: '',
        recovery_code: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post('/confirm-password', {
            onFinish: () => {
                reset('password', 'code', 'recovery_code');
            },
        });
    }

    function toggleMode() {
        setUsingRecoveryCode(!usingRecoveryCode);
        reset('code', 'recovery_code');
    }

    return (
        <AuthLayout
            title={requiresAuthenticator ? 'Confirm your authenticator' : 'Confirm your password'}
            description={
                requiresAuthenticator
                    ? 'This is a secure area. Please verify your authenticator code to continue.'
                    : 'This is a secure area. Please confirm your password to continue.'
            }
        >
            <form onSubmit={submit} className="flex flex-col gap-4">
                {requiresAuthenticator ? (
                    <>
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
                    </>
                ) : (
                    <TextField
                        label="Password"
                        name="password"
                        type="password"
                        autoComplete="current-password"
                        autoFocus
                        required
                        value={data.password}
                        onChange={(value) => setData('password', value)}
                        error={errors.password}
                    />
                )}
                <Button disabled={processing}>Confirm</Button>
            </form>
            {requiresAuthenticator && (
                <button type="button" onClick={toggleMode} className="text-sm text-emerald-400 hover:text-emerald-300">
                    {usingRecoveryCode ? 'Use an authentication code' : 'Use a recovery code'}
                </button>
            )}
        </AuthLayout>
    );
}
