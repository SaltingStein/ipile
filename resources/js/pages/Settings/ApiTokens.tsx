import type { FormEvent } from 'react';
import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AppLayout from '../../components/AppLayout';
import Button from '../../components/ui/Button';
import Panel from '../../components/ui/Panel';
import TextField from '../../components/ui/TextField';

interface ApiToken {
    id: number;
    name: string;
    last_used_at: string | null;
    created_at: string | null;
    expires_at: string | null;
}

interface ApiTokensProps {
    tokens: ApiToken[];
    newToken: string | null;
    lifetimeDays: number;
    idleDays: number;
    expiryChoices: number[];
}

const keepScroll = { preserveScroll: true };

export default function ApiTokens({ tokens, newToken, lifetimeDays, idleDays, expiryChoices }: ApiTokensProps) {
    return (
        <AppLayout title="API tokens">
            <div className="space-y-6">
                <header className="space-y-2">
                    <h1 className="text-2xl font-bold">API tokens</h1>
                    <p className="max-w-3xl text-sm text-slate-300">
                        API tokens let apps, scripts, and background jobs access the app on your behalf without sharing your password.
                        Create one for each integration or service you trust, keep it secret, and send it in the <code className="font-mono">Authorization</code> header as <code className="font-mono">{'Bearer <token>'}</code>.
                    </p>
                </header>

                {newToken && <NewTokenPanel token={newToken} />}
                <CreateTokenPanel lifetimeDays={lifetimeDays} idleDays={idleDays} expiryChoices={expiryChoices} />
                <TokenListPanel tokens={tokens} />
            </div>
        </AppLayout>
    );
}

function NewTokenPanel({ token }: { token: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <Panel title="Your new token" description="Copy it now. For your security it will not be shown again.">
            <code className="block break-all rounded-lg bg-slate-950 p-4 font-mono text-sm text-slate-100">{token}</code>
            <Button
                type="button"
                variant="secondary"
                className="self-start"
                onClick={() => navigator.clipboard.writeText(token).then(() => setCopied(true))}
            >
                {copied ? 'Copied' : 'Copy token'}
            </Button>
            <p className="text-sm text-slate-400">
                Use it in requests like <code className="font-mono">{'Authorization: Bearer <token>'}</code>. See the developer portal for examples.
            </p>
        </Panel>
    );
}

function CreateTokenPanel({ lifetimeDays, idleDays, expiryChoices }: { lifetimeDays: number; idleDays: number; expiryChoices: number[] }) {
    const { data, setData, post, processing, errors, reset } = useForm({ name: '', expires_in_days: '' });
    const longest = lifetimeDays > 0 ? `${lifetimeDays} days (the maximum)` : 'Never';

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post('/settings/api-tokens', { ...keepScroll, onSuccess: () => reset() });
    }

    return (
        <Panel title="Create a token" description="Use this for scripts, integrations, or other tools that need API access without a password. Mobile apps sign in with their own session flow.">
            <form onSubmit={submit} className="flex max-w-md flex-col gap-4">
                <TextField
                    label="Token name"
                    name="token_name"
                    required
                    maxLength={255}
                    hint='So you can recognise it later, e.g. "Reporting script".'
                    value={data.name}
                    onChange={(value) => setData('name', value)}
                    error={errors.name}
                />
                <label htmlFor="token_expiry" className="flex flex-col gap-1.5 text-sm font-medium text-slate-200">
                    Expires after
                    <select
                        id="token_expiry"
                        value={data.expires_in_days}
                        onChange={(event) => setData('expires_in_days', event.target.value)}
                        className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-normal text-slate-100"
                    >
                        {expiryChoices.map((days) => (
                            <option key={days} value={String(days)}>{days} days</option>
                        ))}
                        <option value="">{longest}</option>
                    </select>
                    {errors.expires_in_days && <span className="text-sm font-normal text-rose-400">{errors.expires_in_days}</span>}
                </label>
                {idleDays > 0 && <p className="text-sm text-slate-400">A token that is not used for {idleDays} days stops working.</p>}
                <Button disabled={processing} className="self-start">Create token</Button>
            </form>
        </Panel>
    );
}

function TokenListPanel({ tokens }: { tokens: ApiToken[] }) {
    const date = (value: string | null, fallback: string) => (value ? new Date(value).toLocaleDateString() : fallback);

    return (
        <Panel title="Your tokens" description="Every app and script that can use your account through the API, including signed-in mobile devices.">
            {tokens.length === 0 ? (
                <p className="text-sm text-slate-400">You have no API tokens.</p>
            ) : (
                <ul className="flex flex-col divide-y divide-slate-800">
                    {tokens.map((token) => (
                        <li key={token.id} className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                            <span className="flex flex-col gap-0.5">
                                <span className="text-slate-200">{token.name}</span>
                                <span className="text-slate-400">
                                    Created {date(token.created_at, 'unknown')} · Last used {date(token.last_used_at, 'never')} · Expires {date(token.expires_at, 'never')}
                                </span>
                            </span>
                            <Button
                                type="button"
                                variant="danger"
                                onClick={() => router.delete(`/settings/api-tokens/${token.id}`, keepScroll)}
                            >
                                Revoke
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}
