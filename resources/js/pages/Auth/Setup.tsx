import type { FormEvent, ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import AuthLayout from '../../components/AuthLayout';
import Button from '../../components/ui/Button';
import TextField from '../../components/ui/TextField';

interface ServerCheck {
    key: string;
    label: string;
    ok: boolean;
    required: boolean;
    fix: string;
}

interface SetupProps {
    checks: ServerCheck[];
    isLocal: boolean;
}

export default function Setup({ checks, isLocal }: SetupProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        setup_key: '',
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });
    const neededCount = checks.filter((check) => ! check.ok && check.required).length;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        post('/setup', { onFinish: () => reset('password', 'password_confirmation') });
    }

    return (
        <AuthLayout
            wide
            title="Set up your site"
            description="This site has no super admin yet, so every page opens here until you finish. Only someone who can run commands on the server can complete setup."
        >
            <Step number={1} title="Check the server">
                {isLocal ? (
                    <p className="text-sm text-slate-400">
                        You are running the site on your own computer, so items marked <strong>Needed</strong> can wait. Fix them on the live server before
                        people use it.
                    </p>
                ) : (
                    <p className="text-sm text-slate-400">
                        {neededCount > 0
                            ? `${neededCount} ${neededCount === 1 ? 'item needs' : 'items need'} fixing before people use the site. You can finish setup first and fix ${neededCount === 1 ? 'it' : 'them'} afterwards.`
                            : 'The server is ready.'}
                    </p>
                )}
                <ul className="divide-y divide-slate-800 rounded-lg border border-slate-800 bg-slate-950/40 px-4">
                    {checks.map((check) => (
                        <li key={check.key} className="flex gap-3 py-3 text-sm">
                            <span aria-hidden="true" className={`mt-0.5 font-bold ${check.ok ? 'text-emerald-400' : check.required ? 'text-rose-400' : 'text-amber-400'}`}>
                                {check.ok ? '✓' : check.required ? '✕' : '!'}
                            </span>
                            <span className="flex flex-col gap-0.5">
                                <span className="font-medium text-slate-100">
                                    {check.label}
                                    {! check.ok && (
                                        <span className={`ml-2 rounded px-1.5 py-0.5 text-xs font-semibold ${check.required ? 'bg-rose-500/15 text-rose-300' : 'bg-amber-500/15 text-amber-300'}`}>
                                            {check.required ? 'Needed' : 'Recommended'}
                                        </span>
                                    )}
                                    <span className="sr-only">{check.ok ? ' (done)' : ' (not done yet)'}</span>
                                </span>
                                {! check.ok && <span className="text-slate-400"><Inline text={check.fix} /></span>}
                            </span>
                        </li>
                    ))}
                </ul>
                <p className="text-sm text-slate-400">
                    On every deploy, run <Code>php artisan migrate --force</Code>, <Code>php artisan ipile:sync-permissions</Code> and{' '}
                    <Code>php artisan optimize</Code>. Scheduled tasks need this cron entry on the server:
                </p>
                <Pre>{'* * * * * cd /path/to/site && php artisan schedule:run >> /dev/null 2>&1'}</Pre>
            </Step>

            <Step number={2} title="Get your setup key">
                <p className="text-sm text-slate-300">The key proves you control the server. On the server, in the site's folder, run:</p>
                <Pre>php artisan ipile:setup-key</Pre>
                <ul className="list-disc space-y-1 pl-5 text-sm text-slate-400">
                    <li>It prints a one-time key. A key was also written to the server log (<Code>storage/logs</Code>) the first time this page opened.</li>
                    <li>Do not paste the key into chat, email or tickets. It stops working as soon as setup is complete.</li>
                    <li>If someone else may have seen it, run the command again. The new key replaces the old one.</li>
                    <li>
                        Prefer the terminal? <Code>php artisan ipile:setup</Code> creates the super admin there. If you already have an account, run{' '}
                        <Code>php artisan ipile:make-super-admin you@example.com</Code>.
                    </li>
                </ul>
            </Step>

            <Step number={3} title="Create the super admin">
                <p className="text-sm text-slate-400">
                    This account can change every setting, role and integration. Use an email address only you can read, because password resets go
                    there, and a long password you use nowhere else (a password manager helps).
                </p>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <TextField
                        label="Setup key"
                        name="setup_key"
                        autoComplete="off"
                        autoFocus
                        required
                        spellCheck={false}
                        value={data.setup_key}
                        onChange={(value) => setData('setup_key', value)}
                        error={errors.setup_key}
                    />
                    <TextField
                        label="Name"
                        name="name"
                        autoComplete="name"
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
                    <Button disabled={processing}>Create super admin</Button>
                </form>
            </Step>

            <Step number={4} title="Right after setup">
                <ol className="list-decimal space-y-1 pl-5 text-sm text-slate-400">
                    <li>Turn on two-factor sign-in. You are taken there as soon as the account is created.</li>
                    <li>Connect an email provider in <strong>Admin → Integrations</strong>, so password resets and invitations arrive.</li>
                    <li>Choose who can sign up and whether two-factor sign-in is required in <strong>Admin → Sign-in &amp; 2FA</strong>.</li>
                    <li>Give other staff a role with only the permissions they need, in <strong>Admin → Roles &amp; permissions</strong>, rather than making them super admins.</li>
                </ol>
            </Step>
        </AuthLayout>
    );
}

function Step({ number, title, children }: { number: number; title: string; children: ReactNode }) {
    return (
        <section className="flex flex-col gap-3 border-t border-slate-800 pt-5 first-of-type:border-t-0 first-of-type:pt-0">
            <h2 className="flex items-center gap-3 text-base font-semibold text-slate-100">
                <span aria-hidden="true" className="flex size-7 items-center justify-center rounded-full bg-emerald-500/15 text-sm text-emerald-300">
                    {number}
                </span>
                <span>
                    <span className="sr-only">Step {number}: </span>
                    {title}
                </span>
            </h2>
            {children}
        </section>
    );
}

/** Renders `code` spans written in backticks inside server-provided text. */
function Inline({ text }: { text: string }) {
    return <>{text.split(/(`[^`]+`)/).map((part, index) => part.startsWith('`') && part.endsWith('`')
        ? <Code key={index}>{part.slice(1, -1)}</Code>
        : <span key={index}>{part}</span>)}</>;
}

function Code({ children }: { children: ReactNode }) {
    return <code className="break-words rounded bg-slate-800 px-1.5 py-0.5 font-mono text-[0.85em] text-slate-100">{children}</code>;
}

function Pre({ children }: { children: string }) {
    return <pre className="overflow-x-auto rounded-lg border border-slate-800 bg-slate-950 p-3 font-mono text-xs leading-relaxed text-slate-100">{children}</pre>;
}
