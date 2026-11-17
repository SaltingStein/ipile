import type { KeyboardEvent, ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '../../components/AdminLayout';
import Panel from '../../components/ui/Panel';

interface CatalogEvent {
    key: string;
    label: string;
    description: string;
    sensitivity: number;
    is_failure: boolean;
    actor_is_verified: boolean;
}

interface SecurityGuideProps {
    checklist: { label: string; ok: boolean | null; detail: string }[];
    modes: { label: string; description: string }[];
    actions: { label: string; description: string; threshold: number | null }[];
    incidentThreshold: number;
    retentionDays: number;
    categories: { key: string; label: string; description: string; monitored: boolean; events: CatalogEvent[] }[];
    signals: { key: string; label: string; description: string; weight: number }[];
    urls: { index: string; settings: string };
}

const defineExample = `// app/Providers/AppServiceProvider.php → boot()
use App\\Services\\Security\\Security;
use App\\Services\\Security\\SecurityEventDefinition;

Security::category('orders', 'Orders', 'Refunds, cancellations and exports.');

Security::define(new SecurityEventDefinition(
    key: 'orders.refunded',          // area.what_happened, lower case
    category: 'orders',              // admins can switch whole categories off
    label: 'Order refunded',
    description: 'A refund was issued to the customer.',
    sensitivity: 0.8,                // 0-1: damage if done by the wrong person
));`;

const recordExample = `// 1. Where it happens: about the signed-in user…
Security::record('orders.refunded', ['amount_kobo' => $refund->amount]);

// …or about a specific account (pass null when no account is involved).
Security::recordFor($order->customer, 'orders.refunded');

// 2. On a route: recorded only when the request succeeds (no 4xx/5xx, no validation errors).
Route::post('/orders/{order}/refund', RefundController::class)
    ->middleware('security.track:orders.refunded');

// 3. From an event your app already dispatches.
Security::listen(OrderRefunded::class, 'orders.refunded',
    user: fn (OrderRefunded $event) => $event->order->customer,
    context: fn (OrderRefunded $event) => ['amount_kobo' => $event->amount],
);`;

const failureExample = `// A failed attempt that anyone can trigger (wrong PIN, bad OTP…):
Security::define(new SecurityEventDefinition('wallet.pin_failed', 'wallet', 'Wallet PIN rejected',
    'A wrong wallet PIN was entered.', sensitivity: 0.5,
    isFailure: true,          // counted by the "Recent failed attempts" signal
    actorIsVerified: false,   // responses stop at alerting the owner (no lock-out by strangers)
));`;

const signalExample = `use App\\Services\\Security\\Signals\\SecuritySignal;
use App\\Services\\Security\\Signals\\SignalContext;
use App\\Services\\Security\\Signals\\SignalResult;

class LargeRefundSignal implements SecuritySignal
{
    public function key(): string { return 'large_refund'; }
    public function label(): string { return 'Large refund'; }
    public function description(): string { return 'Refunds above ₦500,000.'; }
    public function weight(): float { return 0.5; }

    public function evaluate(SignalContext $context): SignalResult
    {
        if ($context->definition->key !== 'orders.refunded') {
            return SignalResult::none();
        }

        $amount = (int) ($context->context['amount_kobo'] ?? 0);

        return $amount > 50_000_000
            ? new SignalResult(1.0, 'Refund above ₦500,000.')
            : SignalResult::none();
    }
}

// AppServiceProvider::boot()
Security::signal(LargeRefundSignal::class);`;

const listenExample = `// Forward to a SIEM, Slack or PagerDuty. Implement ShouldQueue for anything slow.
use App\\Events\\Security\\SecurityIncidentOpened;
use App\\Events\\Security\\SecurityRiskAssessed;

Event::listen(SecurityRiskAssessed::class, function (SecurityRiskAssessed $assessed) {
    // $assessed->event (SecurityEvent), $assessed->decisionScore (0-100), $assessed->incident (or null)
});

Event::listen(SecurityIncidentOpened::class, fn (SecurityIncidentOpened $opened) => /* page on-call */ null);`;

const analystExample = `// Use another model or an in-house service for incident analysis.
use App\\Services\\Security\\Analysis\\SecurityAnalyst;

$this->app->bind(SecurityAnalyst::class, MyAnalyst::class); // AppServiceProvider::register()

// MyAnalyst implements isAvailable(): bool and analyze(SecurityIncident): SecurityAnalysis.
// Build its input with IncidentBriefing so the redaction rules still apply.`;

const testExample = `public function test_large_refunds_raise_an_incident(): void
{
    Setting::store(SecuritySettings::MODE, 'monitor');
    $user = User::factory()->create();

    $event = Security::recordFor($user, 'orders.refunded', ['amount_kobo' => 90_000_000]);

    $this->assertContains('large_refund', array_column($event->signals, 'key'));
}`;

type SegmentId = 'get-started' | 'coverage' | 'scoring' | 'privacy' | 'developers';

interface Segment {
    id: SegmentId;
    label: string;
    audience: string;
    badge?: { text: string; tone: 'ok' | 'warn' | 'neutral' };
}

const developerSteps = [
    { id: 'dev-register', title: 'Register your events' },
    { id: 'dev-record', title: 'Record them' },
    { id: 'dev-signals', title: 'Add your own signals' },
    { id: 'dev-react', title: 'React to results' },
    { id: 'dev-analyst', title: 'Swap the AI analyst' },
    { id: 'dev-test', title: 'Test it' },
] as const;

export default function SecurityGuide({ checklist, modes, actions, incidentThreshold, retentionDays, categories, signals, urls }: SecurityGuideProps) {
    const needsAttention = checklist.filter((item) => item.ok === false).length;
    const monitoredCount = categories.filter((category) => category.monitored).length;
    const segments: Segment[] = [
        {
            id: 'get-started',
            label: 'Get started',
            audience: 'Admins setting up Aegis on this server',
            badge: needsAttention > 0 ? { text: `${needsAttention} to fix`, tone: 'warn' } : { text: 'Ready', tone: 'ok' },
        },
        {
            id: 'coverage',
            label: "What's monitored",
            audience: 'Which actions Aegis can see, and what it ignores',
            badge: { text: `${monitoredCount}/${categories.length} on`, tone: 'neutral' },
        },
        { id: 'scoring', label: 'Scoring & responses', audience: 'How risk is scored and what Aegis does about it' },
        { id: 'privacy', label: 'AI & privacy', audience: 'What the optional AI analyst sees, and what it never sees' },
        { id: 'developers', label: 'For developers', audience: 'Adding your own events, signals and integrations' },
    ];
    const [active, setActive] = useSegmentFromHash(segments.map((segment) => segment.id));
    const current = segments.find((segment) => segment.id === active) ?? segments[0];

    return (
        <AdminLayout title="Monitoring guide">
            <p className="max-w-3xl text-sm text-slate-300">
                Aegis is this app's security centre. It records security-relevant actions, scores how risky each one is, groups risky
                activity into incidents for an administrator, and (when allowed) responds automatically. An optional AI analyst explains
                incidents in plain language. This page is generated from the running code and settings, so it is always current.
            </p>

            <SegmentTabs segments={segments} active={active} onChange={setActive} />

            <div id={`panel-${current.id}`} role="tabpanel" aria-labelledby={`tab-${current.id}`} tabIndex={0} className="flex flex-col gap-6 focus-visible:outline-none">
                <p className="text-sm text-slate-400">{current.audience}.</p>

                {active === 'get-started' && <>
                    <Panel title="Server checklist" description={needsAttention > 0
                        ? `${needsAttention} item${needsAttention === 1 ? ' needs' : 's need'} attention (marked ✕). Optional items are marked –.`
                        : 'Everything Aegis needs is in place. Optional items are marked –.'}>
                        <ul className="flex flex-col divide-y divide-slate-800">
                            {checklist.map((item) => (
                                <li key={item.label} className="flex gap-3 py-3 text-sm">
                                    <span aria-hidden="true" className={`mt-0.5 font-bold ${item.ok === true ? 'text-emerald-400' : item.ok === false ? 'text-rose-400' : 'text-slate-500'}`}>
                                        {item.ok === true ? '✓' : item.ok === false ? '✕' : '–'}
                                    </span>
                                    <span className="flex flex-col gap-0.5">
                                        <span className="font-medium text-slate-100">
                                            {item.label}
                                            <span className="sr-only">{item.ok === true ? ' (done)' : item.ok === false ? ' (needs attention)' : ' (optional, not set up)'}</span>
                                        </span>
                                        <span className="text-slate-400"><Inline text={item.detail} /></span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Panel>

                    <Panel title="Setup steps" description="Do these once, in order.">
                        <ol className="list-decimal space-y-2 pl-6 text-sm text-slate-300">
                            <li>Run <Code>php artisan migrate</Code> and <Code>php artisan db:seed --class=RolesAndPermissionsSeeder</Code> (adds the tables and the <Code>view_security</Code> / <Code>manage_security</Code> permissions; admins get <Code>view_security</Code>, super admins get both).</li>
                            <li>Run a queue worker (<Code>php artisan queue:work</Code>) and the scheduler (<Code>* * * * * php artisan schedule:run</Code>). The scheduler trains baselines at 02:30 and deletes old data at 02:15.</li>
                            <li>Leave the mode on <strong>Monitor only</strong> for a week or two. Review incidents, mark false positives, and tune thresholds in <Link href={urls.settings} className="text-emerald-300 hover:text-emerald-200">Settings</Link>.</li>
                            <li>Run <Code>php artisan security:train</Code> once you have a few days of events, so the Markov signal has data.</li>
                            <li>Switch to <strong>Monitor and respond</strong>. Optionally enable the AI analyst (switch on a model in Integrations → AI models, then choose it in Settings).</li>
                        </ol>
                    </Panel>

                    <Panel title="Modes" description="Chosen in Settings. You can switch at any time.">
                        <dl className="grid gap-3 text-sm sm:grid-cols-3">
                            {modes.map((mode) => (
                                <div key={mode.label} className="rounded-lg border border-slate-800 p-3">
                                    <dt className="font-semibold text-slate-100">{mode.label}</dt>
                                    <dd className="mt-1 text-slate-400">{mode.description}</dd>
                                </div>
                            ))}
                        </dl>
                    </Panel>
                </>}

                {active === 'coverage' && <>
                    <Panel title="Recorded events" description="Aegis only sees what is recorded. Open a category to see its event types. Switch categories on or off in Settings.">
                        <div className="flex flex-col gap-3">
                            {categories.map((category) => (
                                <details key={category.key} className="group rounded-xl border border-slate-800 bg-slate-950/40">
                                    <summary className="flex cursor-pointer list-none flex-wrap items-center gap-3 px-4 py-3 focus-visible:outline-2 focus-visible:outline-emerald-400">
                                        <span className="flex flex-1 flex-col">
                                            <span className="font-semibold text-slate-100">{category.label}</span>
                                            {category.description && <span className="text-sm text-slate-400">{category.description}</span>}
                                        </span>
                                        <span className={`rounded-full border px-2 py-0.5 text-xs ${category.monitored ? 'border-emerald-700 text-emerald-300' : 'border-slate-700 text-slate-400'}`}>
                                            {category.monitored ? 'Monitored' : 'Out of scope'}
                                        </span>
                                        <span className="rounded-full bg-slate-800 px-2.5 py-0.5 text-xs text-slate-200">{category.events.length} events</span>
                                        <span aria-hidden="true" className="text-slate-500 transition group-open:rotate-180">▾</span>
                                    </summary>
                                    <div className="overflow-x-auto border-t border-slate-800">
                                        <table className="w-full text-left text-sm">
                                            <thead className="bg-slate-900 text-slate-400">
                                                <tr>
                                                    <th className="px-3 py-2 font-medium">Event</th>
                                                    <th className="px-3 py-2 font-medium">Key</th>
                                                    <th className="px-3 py-2 font-medium">Sensitivity</th>
                                                    <th className="px-3 py-2 font-medium">Notes</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {category.events.map((event) => (
                                                    <tr key={event.key} className="border-t border-slate-800 align-top">
                                                        <td className="px-3 py-2 text-slate-100">{event.label}<span className="block text-xs text-slate-400">{event.description}</span></td>
                                                        <td className="px-3 py-2"><Code>{event.key}</Code></td>
                                                        <td className="px-3 py-2 tabular-nums text-slate-300">{event.sensitivity.toFixed(2)}</td>
                                                        <td className="px-3 py-2 text-slate-400">
                                                            {[event.is_failure ? 'Failed attempt' : null, event.actor_is_verified ? null : 'Responses capped at alert'].filter(Boolean).join(' · ') || '—'}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </details>
                            ))}
                        </div>
                    </Panel>

                    <Panel title="Not in scope by design" description="Aegis does not try to cover these.">
                        <ul className="list-disc space-y-1 pl-6 text-sm text-slate-400">
                            <li>Page views and reads. Aegis records actions that change something or prove identity, not browsing.</li>
                            <li>Network attacks (DDoS, scanning). Use your WAF, CDN and the per-route rate limits for those.</li>
                            <li>Your own features until you register their events (see <SegmentLink to="developers" onChange={setActive}>For developers</SegmentLink>). Anything you do not record is invisible to Aegis.</li>
                        </ul>
                    </Panel>

                    <Panel title="Data kept" description="Mention this in your privacy notice.">
                        <p className="text-sm text-slate-300">
                            Event type, account, IP address, user agent, small context values and the scores, for {retentionDays} days.
                        </p>
                    </Panel>
                </>}

                {active === 'scoring' && <>
                    <Panel title="Automatic responses" description={`Each response fires once per episode when the decision score reaches its threshold. An incident opens at ${incidentThreshold}. Change thresholds in Settings.`}>
                        <div className="overflow-x-auto rounded-lg border border-slate-800">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-slate-900 text-slate-400">
                                    <tr><th className="px-3 py-2 font-medium">Response</th><th className="px-3 py-2 font-medium">Threshold</th><th className="px-3 py-2 font-medium">What happens</th></tr>
                                </thead>
                                <tbody>
                                    {actions.map((action) => (
                                        <tr key={action.label} className="border-t border-slate-800 align-top">
                                            <td className="px-3 py-2 text-slate-100">{action.label}</td>
                                            <td className="px-3 py-2 tabular-nums text-slate-300">{action.threshold ?? 'Never'}</td>
                                            <td className="px-3 py-2 text-slate-400">{action.description}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Panel>

                    <Panel title="Signals" description="Deterministic and explainable: every score on an incident page can be traced to these signals.">
                        <div className="overflow-x-auto rounded-lg border border-slate-800">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-slate-900 text-slate-400">
                                    <tr><th className="px-3 py-2 font-medium">Signal</th><th className="px-3 py-2 font-medium">Weight</th><th className="px-3 py-2 font-medium">What it looks at</th></tr>
                                </thead>
                                <tbody>
                                    {signals.map((signal) => (
                                        <tr key={signal.key} className="border-t border-slate-800 align-top">
                                            <td className="px-3 py-2 text-slate-100">{signal.label}<span className="block"><Code>{signal.key}</Code></span></td>
                                            <td className="px-3 py-2 tabular-nums text-slate-300">{signal.weight}</td>
                                            <td className="px-3 py-2 text-slate-400">{signal.description}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Panel>

                    <Panel title="How the score is worked out" description="The maths behind the decision score, for anyone tuning or auditing it.">
                        <Pre>{`evidence     = 1 − Π (1 − weight × signal score)          (noisy-OR: one strong signal is enough)
event score  = 100 × evidence × (0.4 + 0.6 × sensitivity)
posture      = Markov chain over normal → elevated → high → critical, moved by each event, decaying when quiet
decision     = max(event score, posture score)  →  incident at ≥ ${incidentThreshold}, responses at their thresholds`}</Pre>
                        <ul className="list-disc space-y-2 pl-6 text-sm text-slate-300">
                            <li><strong>Markov sequence model.</strong> Nightly training counts how often each event follows another, per account, per role and for everyone. A new account borrows from its role, a new role from everyone. With no history nothing looks unusual, so new installs do not flood you with incidents.</li>
                            <li><strong>Posture chain.</strong> Several moderately risky events in a row escalate the account; a single odd event fades over the following hours. Resolving an incident resets the account to normal.</li>
                            <li><strong>Escalation only.</strong> Each response happens once per episode. A stronger one can still follow, but the same alert or sign-out is not repeated on every request.</li>
                            <li><strong>Learning safely.</strong> Events in incidents resolved as “Confirmed attack” are left out of training, so attacks are never learned as normal.</li>
                        </ul>
                    </Panel>
                </>}

                {active === 'privacy' && (
                    <Panel title="AI analyst and privacy" description="How the optional AI analyst is kept safe, whichever AI model you choose.">
                        <ul className="list-disc space-y-2 pl-6 text-sm text-slate-300">
                            <li><strong>Advisory only.</strong> The Markov engine and your thresholds decide responses. The AI writes an explanation, a verdict and suggested steps for a human.</li>
                            <li><strong>Redacted input.</strong> Sent: event types, scores, signal names, relative timings, a device name such as “Chrome on Windows”, role names, account age and whether two-factor is on. Never sent: names, emails, phone numbers, IP addresses, raw user agents or text values.</li>
                            <li><strong>No prompt injection path.</strong> Nothing an attacker can type (user agent, form input) reaches the model.</li>
                            <li><strong>Cost control.</strong> Each incident is analysed once automatically, only above the AI threshold; re-analysis is a manual button.</li>
                        </ul>
                    </Panel>
                )}

                {active === 'developers' && (
                    <Panel title="Extending Aegis" description="For developers building on this boilerplate. Everything below is plain Laravel; no package to publish.">
                        <nav aria-label="Developer steps" className="flex flex-wrap gap-2 text-sm">
                            {developerSteps.map((step, index) => (
                                <a key={step.id} href={`#${step.id}`}
                                    onClick={(event) => { event.preventDefault(); openStep(step.id); }}
                                    className="rounded-full border border-slate-800 px-3 py-1 text-slate-300 hover:border-emerald-500/40 hover:text-slate-50">
                                    {index + 1}. {step.title}
                                </a>
                            ))}
                        </nav>
                        <Step id="dev-register" number={1} title="Register your events" open>
                            <p>Give each security-relevant action in your product an event type. Choose sensitivity by asking “how bad if an attacker did this?”: 0.1 harmless, 0.5 notable, 0.9 account-takeover territory.</p>
                            <Pre>{defineExample}</Pre>
                            <Pre>{failureExample}</Pre>
                        </Step>
                        <Step id="dev-record" number={2} title="Record them">
                            <p>Three equivalent ways; pick whichever reads best. Recording is synchronous (so responses can act on the current request), fails open (an error never breaks the user's request) and does nothing when Aegis is off or the category is out of scope. An unknown event key throws, so typos surface in development.</p>
                            <Pre>{recordExample}</Pre>
                            <p>Context is for small, non-secret values. Only numbers and true/false values are ever sent to the AI analyst. Never put passwords, tokens, card numbers or free text typed by users in context.</p>
                        </Step>
                        <Step id="dev-signals" number={3} title="Add your own signals">
                            <p>A signal returns a 0-1 score for an event. Keep it fast (indexed queries only). A signal that throws is skipped and logged; the others still score the event.</p>
                            <Pre>{signalExample}</Pre>
                        </Step>
                        <Step id="dev-react" number={4} title="React to results">
                            <Pre>{listenExample}</Pre>
                        </Step>
                        <Step id="dev-analyst" number={5} title="Swap the AI analyst">
                            <Pre>{analystExample}</Pre>
                        </Step>
                        <Step id="dev-test" number={6} title="Test it">
                            <Pre>{testExample}</Pre>
                        </Step>
                    </Panel>
                )}
            </div>
        </AdminLayout>
    );
}

/**
 * The active segment, kept in the URL hash (e.g. /admin/security/guide#developers) so a segment can be linked
 * to and survives a refresh. `replaceState` keeps Inertia's history state and does not add a history entry per tab.
 */
function useSegmentFromHash(ids: SegmentId[]): [SegmentId, (id: SegmentId) => void] {
    const fromHash = (): SegmentId => {
        const hash = typeof window === 'undefined' ? '' : window.location.hash.slice(1);
        return (ids as string[]).includes(hash) ? (hash as SegmentId) : ids[0];
    };
    const [active, setActiveState] = useState<SegmentId>(fromHash);

    useEffect(() => {
        const sync = () => setActiveState(fromHash());
        window.addEventListener('hashchange', sync);
        return () => window.removeEventListener('hashchange', sync);
    }, []);

    const setActive = (id: SegmentId) => {
        setActiveState(id);
        window.history.replaceState(window.history.state, '', `#${id}`);
    };

    return [active, setActive];
}

function SegmentTabs({ segments, active, onChange }: { segments: Segment[]; active: SegmentId; onChange: (id: SegmentId) => void }) {
    const tabs = useRef<Record<string, HTMLButtonElement | null>>({});

    // Arrow keys, Home and End move between tabs (WAI-ARIA tabs pattern).
    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const index = segments.findIndex((segment) => segment.id === active);
        const next = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: segments.length - 1 }[event.key];

        if (next === undefined) {
            return;
        }

        event.preventDefault();
        const target = segments[(next + segments.length) % segments.length];
        onChange(target.id);
        tabs.current[target.id]?.focus();
    };

    const badgeTone = { ok: 'bg-emerald-500/15 text-emerald-300', warn: 'bg-rose-500/15 text-rose-300', neutral: 'bg-slate-800 text-slate-300' };

    return (
        <div role="tablist" aria-label="Guide sections" onKeyDown={onKeyDown}
            className="flex gap-1 overflow-x-auto rounded-2xl border border-slate-800 bg-slate-900/80 p-1">
            {segments.map((segment) => {
                const isActive = segment.id === active;

                return (
                    <button key={segment.id} ref={(element) => { tabs.current[segment.id] = element; }}
                        id={`tab-${segment.id}`} type="button" role="tab" aria-selected={isActive} aria-controls={`panel-${segment.id}`}
                        tabIndex={isActive ? 0 : -1} onClick={() => onChange(segment.id)}
                        className={`flex shrink-0 items-center gap-2 rounded-xl px-4 py-2 text-sm transition focus-visible:outline-2 focus-visible:outline-emerald-400 ${isActive ? 'bg-emerald-500/10 font-semibold text-emerald-300' : 'text-slate-300 hover:bg-slate-800 hover:text-slate-50'}`}>
                        {segment.label}
                        {segment.badge && <span className={`rounded-full px-2 py-0.5 text-xs font-normal ${badgeTone[segment.badge.tone]}`}>{segment.badge.text}</span>}
                    </button>
                );
            })}
        </div>
    );
}

function SegmentLink({ to, onChange, children }: { to: SegmentId; onChange: (id: SegmentId) => void; children: ReactNode }) {
    return (
        <a href={`#${to}`} onClick={(event) => { event.preventDefault(); onChange(to); window.scrollTo({ top: 0 }); }}
            className="text-emerald-300 hover:text-emerald-200">
            {children}
        </a>
    );
}

/** Open a developer step and scroll to it (used by the step index). */
function openStep(id: string) {
    const element = document.getElementById(id);

    if (element instanceof HTMLDetailsElement) {
        element.open = true;
        element.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function Step({ id, number, title, open = false, children }: { id: string; number: number; title: string; open?: boolean; children: ReactNode }) {
    return (
        <details id={id} open={open} className="group scroll-mt-6 rounded-xl border border-slate-800 bg-slate-950/40">
            <summary className="flex cursor-pointer list-none items-center gap-3 px-4 py-3 font-semibold text-slate-100 focus-visible:outline-2 focus-visible:outline-emerald-400">
                <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-800 text-xs text-slate-300">{number}</span>
                <span className="flex-1">{title}</span>
                <span aria-hidden="true" className="text-slate-500 transition group-open:rotate-180">▾</span>
            </summary>
            <div className="flex flex-col gap-3 border-t border-slate-800 px-4 py-4 text-sm text-slate-300">
                {children}
            </div>
        </details>
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
    return <pre className="overflow-x-auto rounded-lg border border-slate-800 bg-slate-950 p-4 font-mono text-xs leading-relaxed text-slate-100">{children}</pre>;
}
