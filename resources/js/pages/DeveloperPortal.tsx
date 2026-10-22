import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import PublicLayout from '../components/PublicLayout';
import type { SharedPageProps } from '../types';

interface RateLimit {
    method: string;
    path: string;
    per_minute: number;
    by: 'user' | 'ip';
}

interface DeveloperPortalProps {
    baseUrl: string;
    docsUrl: string;
    specUrl: string;
    webhookUrl: string;
    baselineLimits: { authenticated: number; public: number };
    rateLimits: RateLimit[];
    loginMaxAttempts: number;
    twoFactorMaxAttempts: number;
    tokenLifetimeDays: number;
    tokenIdleDays: number;
}

/** How each implemented payment gateway's webhook is verified (see App\Services\Payments\*\*Gateway::verifyWebhookSignature). */
const webhookChecks = [
    { provider: 'paystack', label: 'Paystack', check: 'x-paystack-signature header: HMAC-SHA512 of the raw body with the secret key.' },
    { provider: 'squad', label: 'Squad (GTCO)', check: 'x-squad-encrypted-body header: HMAC-SHA512 of the raw body with the secret key (hex, case-insensitive).' },
    { provider: 'flutterwave', label: 'Flutterwave', check: 'verif-hash header: must equal the webhook secret hash set in Admin → Integrations.' },
    { provider: 'opay', label: 'OPay', check: 'sha512 field in the body: HMAC-SHA3-512 of OPay’s fixed payload string with the secret key.' },
];

export default function DeveloperPortal({ baseUrl, docsUrl, specUrl, webhookUrl, baselineLimits, rateLimits, loginMaxAttempts, twoFactorMaxAttempts, tokenLifetimeDays, tokenIdleDays }: DeveloperPortalProps) {
    const { branding } = usePage<SharedPageProps>().props;

    return <PublicLayout title={`Developers | ${branding.name}`}>
        <main className="mx-auto w-full max-w-3xl flex-1 space-y-10 px-6 py-10 text-slate-300">
            <header className="space-y-3">
                <h1 className="text-3xl font-semibold text-slate-50">{branding.name} API</h1>
                <p>A JSON API for mobile and server apps. Everything here is also available in the web app, and both use the same rules.</p>
                <div className="flex flex-wrap gap-4 text-sm">
                    <a href={docsUrl} className="rounded-full bg-emerald-500 px-4 py-2 font-medium text-slate-950 hover:bg-emerald-400">API reference</a>
                    <a href={specUrl} className="rounded-full border border-slate-700 px-4 py-2 font-medium text-slate-100 hover:border-slate-500">OpenAPI JSON</a>
                </div>
                <p className="text-sm">Base URL: <Code>{baseUrl}</Code></p>
            </header>

            <Section title="Getting started">
                <p>1. Sign in with email and password. <Code>device_name</Code> labels the token (for example the phone model) so the user can revoke it later.</p>
                <Pre>{`curl -X POST ${baseUrl}/auth/login \\
  -H "Accept: application/json" -H "Content-Type: application/json" \\
  -d '{"email":"you@example.com","password":"secret","device_name":"Pixel 9"}'`}</Pre>
                <p>The response contains <Code>token</Code> and the <Code>user</Code>. New users can call <Code>POST /auth/register</Code> with the same <Code>device_name</Code> field.</p>
                <p>2. Send the token on every other request:</p>
                <Pre>{`curl ${baseUrl}/auth/user \\
  -H "Accept: application/json" -H "Authorization: Bearer <token>"`}</Pre>
                <p>Send <Code>Accept: application/json</Code> on every request.</p>
            </Section>

            <Section title="Authentication">
                <ul className="list-disc space-y-2 pl-6">
                    <li>Tokens are Sanctum personal access tokens. List them with <Code>GET /auth/tokens</Code>, revoke one with <Code>DELETE /auth/tokens/{'{id}'}</Code>, and revoke the current one with <Code>POST /auth/logout</Code>. Users can also manage them in the web app under API tokens.</li>
                    <li>
                        {tokenLifetimeDays > 0 ? <>Tokens expire {tokenLifetimeDays} days after they are created (<Code>expires_at</Code> in the token list). </> : <>Tokens do not expire on a fixed date. </>}
                        {tokenIdleDays > 0 && <>A token not used for {tokenIdleDays} days stops working. </>}
                        An expired or revoked token gets <Code>401</Code>: send the user back to sign in.
                    </li>
                    <li>If the account has two-factor authentication, login returns <Code>{'{"two_factor_required": true, "challenge_token": "…", "expires_in": 300}'}</Code> instead of a token. Send <Code>challenge_token</Code> with a <Code>code</Code> (or <Code>recovery_code</Code>) to <Code>POST /auth/two-factor-challenge</Code> within 5 minutes to get the token.</li>
                    <li>Google sign-in: sign in with Google in the app, then send its <Code>access_token</Code> and a <Code>device_name</Code> to <Code>POST /auth/oauth/google</Code>. <Code>GET /auth/oauth/providers</Code> lists the providers that are switched on.</li>
                    <li>Payment endpoints also need a verified email address. Suspended accounts get <Code>403</Code> on every authenticated endpoint.</li>
                </ul>
            </Section>

            <Section title="Rate limits">
                <p>When a limit is exceeded the API returns <Code>429</Code>. The <Code>Retry-After</Code> header gives the number of seconds to wait.</p>
                <ul className="list-disc space-y-2 pl-6">
                    <li>Every endpoint that needs a token: {baselineLimits.authenticated} requests per minute per user, shared across those endpoints.</li>
                    <li>Every public endpoint: {baselineLimits.public} requests per minute per IP address.</li>
                </ul>
                <p>These endpoints also have their own, stricter limit:</p>
                <div className="overflow-x-auto rounded-lg border border-slate-800">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-slate-900 text-slate-400"><tr><th className="px-4 py-2 font-medium">Endpoint</th><th className="px-4 py-2 font-medium">Limit</th></tr></thead>
                        <tbody>
                            {rateLimits.map((limit) => <tr key={`${limit.method} ${limit.path}`} className="border-t border-slate-800">
                                <td className="px-4 py-2"><Code>{limit.method} {limit.path}</Code></td>
                                <td className="px-4 py-2">{limit.per_minute} per minute per {limit.by === 'user' ? 'user' : 'IP address'}</td>
                            </tr>)}
                        </tbody>
                    </table>
                </div>
                <ul className="list-disc space-y-2 pl-6">
                    <li>Login allows {loginMaxAttempts} failed attempts per email address and IP. After that it returns <Code>422</Code> with a “too many login attempts” message on <Code>email</Code>.</li>
                    <li>After {twoFactorMaxAttempts} wrong two-factor codes the challenge is cancelled and the user must sign in again.</li>
                </ul>
            </Section>

            <Section title="Errors">
                <p>Errors are JSON with a <Code>message</Code>. Validation errors (<Code>422</Code>) also list the problems for each field:</p>
                <Pre>{`{
  "message": "The email field is required.",
  "errors": { "email": ["The email field is required."] }
}`}</Pre>
                <ul className="list-disc space-y-2 pl-6">
                    <li><Code>401</Code>: missing, invalid or revoked token.</li>
                    <li><Code>403</Code>: signed in but not allowed (suspended account, unverified email, or not a member of the team).</li>
                    <li><Code>404</Code>: not found, or not visible to you.</li>
                    <li><Code>429</Code>: rate limited (see above).</li>
                    <li><Code>502</Code>: a payment provider failed or rejected the request. The payment is marked failed.</li>
                </ul>
            </Section>

            <Section title="Payment webhooks">
                <p>Payment providers notify <Code>{`POST ${webhookUrl}/{provider}`}</Code>. Set this URL in each provider’s dashboard. You do not call it yourself. Each request is checked before anything changes, and requests that fail the check are rejected without changing any payment. A payment is updated only once, and only when the amount and currency match.</p>
                <div className="overflow-x-auto rounded-lg border border-slate-800">
                    <table className="w-full text-left text-sm">
                        <thead className="bg-slate-900 text-slate-400"><tr><th className="px-4 py-2 font-medium">Provider</th><th className="px-4 py-2 font-medium">How it is verified</th></tr></thead>
                        <tbody>
                            {webhookChecks.map((entry) => <tr key={entry.provider} className="border-t border-slate-800 align-top">
                                <td className="px-4 py-2"><span className="text-slate-100">{entry.label}</span><br /><Code>{`${webhookUrl}/${entry.provider}`}</Code></td>
                                <td className="px-4 py-2">{entry.check}</td>
                            </tr>)}
                        </tbody>
                    </table>
                </div>
                <p>Your app should confirm a payment with <Code>{'POST /payments/{reference}/verify'}</Code> rather than trust the redirect back from checkout.</p>
            </Section>
        </main>
    </PublicLayout>;
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return <section className="space-y-4">
        <h2 className="text-xl font-semibold text-slate-50">{title}</h2>
        {children}
    </section>;
}

function Code({ children }: { children: ReactNode }) {
    return <code className="break-words rounded bg-slate-800 px-1.5 py-0.5 font-mono text-[0.85em] text-slate-100">{children}</code>;
}

function Pre({ children }: { children: string }) {
    return <pre className="overflow-x-auto rounded-lg border border-slate-800 bg-slate-900 p-4 font-mono text-sm text-slate-100">{children}</pre>;
}
