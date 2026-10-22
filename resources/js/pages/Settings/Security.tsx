import type { FormEvent } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import Button from '../../components/ui/Button';
import Panel from '../../components/ui/Panel';
import RiskBadge from '../../components/RiskBadge';
import StatusMessage from '../../components/ui/StatusMessage';
import TextField from '../../components/ui/TextField';
import type { SharedPageProps } from '../../types';

interface TwoFactorState {
    enabled: boolean;
    pending: boolean;
    required: boolean;
    qrCodeSvg: string | null;
    setupKey: string | null;
    recoveryCodes: string[];
}

interface ConnectedAccount {
    provider: string;
    label: string;
    connected: boolean;
    email: string | null;
    canConnect: boolean;
}

interface BrowserSession {
    id: string;
    ip_address: string | null;
    user_agent: string | null;
    last_active_at: string;
    is_current: boolean;
}

interface SecurityActivity {
    id: number;
    label: string;
    ip_address: string | null;
    device: string | null;
    risk_score: number;
    posture_score: number;
    occurred_at: string;
}

interface SecurityProps {
    hasPassword: boolean;
    twoFactor: TwoFactorState;
    connectedAccounts: ConnectedAccount[];
    sessions: { supported: boolean; items: BrowserSession[] };
    trustedDevices: { count: number; days: number };
    activity: { enabled: boolean; flagAt: number; items: SecurityActivity[] };
}

const keepScroll = { preserveScroll: true };

export default function Security({ hasPassword, twoFactor, connectedAccounts, sessions, trustedDevices, activity }: SecurityProps) {
    return (
        <AppLayout title="Security">
            <h1 className="text-2xl font-bold">Security</h1>
            <PasswordPanel hasPassword={hasPassword} />
            <TwoFactorPanel twoFactor={twoFactor} />
            {connectedAccounts.length > 0 && <ConnectedAccountsPanel accounts={connectedAccounts} />}
            <BrowserSessionsPanel hasPassword={hasPassword} sessions={sessions} />
            {trustedDevices.days > 0 && <TrustedDevicesPanel count={trustedDevices.count} />}
            {(activity.enabled || activity.items.length > 0) && <ActivityPanel activity={activity} />}
        </AppLayout>
    );
}

function PasswordPanel({ hasPassword }: { hasPassword: boolean }) {
    const { data, setData, put, processing, errors, reset } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        put('/settings/password', { ...keepScroll, onFinish: () => reset() });
    }

    return (
        <Panel
            title={hasPassword ? 'Change password' : 'Set a password'}
            description={hasPassword
                ? 'Use a long, unique password. Other devices will be signed out.'
                : 'You currently sign in with a connected account. Add a password to also sign in with your email.'}
        >
            <form onSubmit={submit} className="grid max-w-md gap-4">
                {hasPassword && (
                    <TextField
                        label="Current password"
                        name="current_password"
                        type="password"
                        autoComplete="current-password"
                        required
                        value={data.current_password}
                        onChange={(value) => setData('current_password', value)}
                        error={errors.current_password}
                    />
                )}
                <TextField
                    label="New password"
                    name="password"
                    type="password"
                    autoComplete="new-password"
                    required
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
                <Button disabled={processing} className="justify-self-start">Save password</Button>
            </form>
        </Panel>
    );
}

function TwoFactorPanel({ twoFactor }: { twoFactor: TwoFactorState }) {
    const { errors } = usePage<SharedPageProps>().props;
    const confirmForm = useForm({ code: '' });

    function confirm(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        confirmForm.post('/settings/two-factor/confirm', { ...keepScroll, onFinish: () => confirmForm.reset() });
    }

    return (
        <Panel
            title="Two-factor authentication"
            description="Protect your account with a code from an authenticator app such as Google Authenticator or Authy."
        >
            {twoFactor.required && !twoFactor.enabled && (
                <StatusMessage tone="error" message="Your role requires two-factor authentication. Set it up to continue using the app." />
            )}
            <StatusMessage tone="error" message={errors.two_factor} />

            {!twoFactor.enabled && !twoFactor.pending && (
                <Button type="button" className="self-start" onClick={() => router.post('/settings/two-factor', {}, keepScroll)}>
                    Set up authenticator app
                </Button>
            )}

            {twoFactor.pending && (
                <div className="flex flex-col gap-4">
                    <p className="text-sm text-slate-300">
                        Scan this QR code with your authenticator app, or enter the setup key, then type the 6-digit code it shows.
                    </p>
                    {twoFactor.qrCodeSvg && (
                        // The SVG is generated server-side from the provisioning URL; it contains no user-supplied markup.
                        <div className="w-fit rounded-lg bg-[#fff] p-3" dangerouslySetInnerHTML={{ __html: twoFactor.qrCodeSvg }} />
                    )}
                    <p className="text-sm text-slate-400">
                        Setup key: <code className="rounded bg-slate-950 px-2 py-1 font-mono text-slate-100">{twoFactor.setupKey}</code>
                    </p>
                    <form onSubmit={confirm} className="flex max-w-md flex-col gap-3">
                        <TextField
                            label="Code from your app"
                            name="code"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={6}
                            required
                            value={confirmForm.data.code}
                            onChange={(value) => confirmForm.setData('code', value)}
                            error={confirmForm.errors.code}
                        />
                        <div className="flex gap-3">
                            <Button disabled={confirmForm.processing}>Confirm</Button>
                            <Button type="button" variant="secondary" onClick={() => router.delete('/settings/two-factor', keepScroll)}>
                                Cancel
                            </Button>
                        </div>
                    </form>
                </div>
            )}

            {twoFactor.enabled && (
                <div className="flex flex-col gap-4">
                    <p className="text-sm text-emerald-300">Two-factor authentication is on.</p>
                    <div className="flex flex-col gap-2">
                        <p className="text-sm text-slate-300">
                            Recovery codes let you sign in if you lose your phone. Each code works once. Store them in a password manager.
                        </p>
                        <ul className="grid max-w-md grid-cols-2 gap-2 rounded-lg bg-slate-950 p-4 font-mono text-sm text-slate-100">
                            {twoFactor.recoveryCodes.map((code) => (
                                <li key={code}>{code}</li>
                            ))}
                        </ul>
                    </div>
                    <div className="flex flex-wrap gap-3">
                        <Button type="button" variant="secondary" onClick={() => router.post('/settings/two-factor/recovery-codes', {}, keepScroll)}>
                            Regenerate recovery codes
                        </Button>
                        {!twoFactor.required && (
                            <Button type="button" variant="danger" onClick={() => router.delete('/settings/two-factor', keepScroll)}>
                                Turn off
                            </Button>
                        )}
                    </div>
                </div>
            )}
        </Panel>
    );
}

function ConnectedAccountsPanel({ accounts }: { accounts: ConnectedAccount[] }) {
    const { errors } = usePage<SharedPageProps>().props;

    return (
        <Panel title="Connected accounts" description="Sign in with these accounts instead of your password.">
            <StatusMessage tone="error" message={errors.oauth} />
            <ul className="flex flex-col divide-y divide-slate-800">
                {accounts.map((account) => (
                    <li key={account.provider} className="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div className="flex flex-col">
                            <span className="font-medium">{account.label}</span>
                            <span className="text-sm text-slate-400">{account.connected ? account.email ?? 'Connected' : 'Not connected'}</span>
                        </div>
                        {account.connected ? (
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => router.delete(`/settings/connected-accounts/${account.provider}`, keepScroll)}
                            >
                                Disconnect
                            </Button>
                        ) : account.canConnect && (
                            <a
                                href={`/auth/${account.provider}/redirect`}
                                className="rounded-lg border border-slate-700 px-4 py-2 text-sm font-semibold hover:border-slate-500"
                            >
                                Connect
                            </a>
                        )}
                    </li>
                ))}
            </ul>
        </Panel>
    );
}

function BrowserSessionsPanel({ hasPassword, sessions }: { hasPassword: boolean; sessions: SecurityProps['sessions'] }) {
    const { data, setData, delete: destroy, processing, errors, reset } = useForm({ password: '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        destroy('/settings/browser-sessions', { ...keepScroll, onFinish: () => reset() });
    }

    return (
        <Panel title="Browser sessions" description="Sign out of your account on all other browsers and devices.">
            {sessions.supported && (
                <ul className="flex flex-col divide-y divide-slate-800">
                    {sessions.items.map((session) => (
                        <li key={session.id} className="flex flex-col gap-0.5 py-3 text-sm">
                            <span className="text-slate-200">{session.user_agent ?? 'Unknown browser'}</span>
                            <span className="text-slate-400">
                                {session.ip_address ?? 'Unknown IP'} ·{' '}
                                {session.is_current
                                    ? <span className="text-emerald-400">This device</span>
                                    : `Last active ${new Date(session.last_active_at).toLocaleString()}`}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            <form onSubmit={submit} className="flex max-w-md flex-col gap-3">
                {hasPassword && (
                    <TextField
                        label="Confirm with your password"
                        name="logout_password"
                        type="password"
                        autoComplete="current-password"
                        required
                        value={data.password}
                        onChange={(value) => setData('password', value)}
                        error={errors.password}
                    />
                )}
                <Button variant="secondary" disabled={processing} className="self-start">
                    Log out other browser sessions
                </Button>
            </form>
        </Panel>
    );
}

function TrustedDevicesPanel({ count }: { count: number }) {
    return (
        <Panel title="Trusted devices" description="Devices that skip the two-factor code at sign-in.">
            <p className="text-sm text-slate-300">
                {count === 0 ? 'No trusted devices.' : `${count} trusted ${count === 1 ? 'device' : 'devices'}.`}
            </p>
            {count > 0 && (
                <Button type="button" variant="secondary" className="self-start" onClick={() => router.delete('/settings/trusted-devices', keepScroll)}>
                    Remove all trusted devices
                </Button>
            )}
        </Panel>
    );
}

function ActivityPanel({ activity }: { activity: SecurityProps['activity'] }) {
    return (
        <Panel title="Recent security activity" description="Sign-ins, password and two-factor changes and other sensitive actions on your account. If something here was not you, change your password and log out other sessions.">
            {activity.items.length === 0 ? (
                <p className="text-sm text-slate-400">No activity recorded yet.</p>
            ) : (
                <ul className="flex flex-col divide-y divide-slate-800">
                    {activity.items.map((item) => {
                        const score = Math.max(item.risk_score, item.posture_score);

                        return (
                            <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
                                <span className="flex flex-col gap-0.5">
                                    <span className="text-slate-200">{item.label}</span>
                                    <span className="text-slate-400">
                                        {item.device ?? 'Unknown device'} · {item.ip_address ?? 'Unknown IP'} · {new Date(item.occurred_at).toLocaleString()}
                                    </span>
                                </span>
                                {score >= activity.flagAt && <RiskBadge score={score} showScore={false} />}
                            </li>
                        );
                    })}
                </ul>
            )}
        </Panel>
    );
}