import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import AppLayout from '../components/AppLayout';
import Button from '../components/ui/Button';
import Panel from '../components/ui/Panel';
import StatusMessage from '../components/ui/StatusMessage';

interface Turn {
    role: 'user' | 'assistant';
    content: string;
    /** The assistant's Markdown answer as safe HTML, rendered by the server. */
    html?: string | null;
    /** Which mode answered (assistant turns only), e.g. "Site guidance". */
    mode?: string | null;
}

interface AssistantProps {
    history: Turn[];
    figures: string[];
    dailyLimit: number;
    /** Staff modes this person can use, e.g. ["Site guidance", "Analytics"]. */
    staffModes: string[];
}

/**
 * The XSRF-TOKEN cookie Laravel sets, for the JSON requests.
 */
function xsrfToken(): string {
    return decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
}

export default function Assistant({ history, figures, dailyLimit, staffModes }: AssistantProps) {
    const [turns, setTurns] = useState<Turn[]>(history);
    const [message, setMessage] = useState('');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [locked, setLocked] = useState<{ message: string; redirect: string } | null>(null);
    const end = useRef<HTMLDivElement>(null);

    useEffect(() => end.current?.scrollIntoView({ block: 'end' }), [turns, sending]);

    async function send(event: FormEvent) {
        event.preventDefault();
        const text = message.trim();

        if (text === '' || sending) {
            return;
        }

        setSending(true);
        setError(null);
        setTurns((current) => [...current, { role: 'user', content: text }]);
        setMessage('');

        try {
            const response = await fetch('/assistant/messages', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify({ message: text }),
            });
            const json = (await response.json().catch(() => ({}))) as { reply?: string; html?: string; mode_label?: string; message?: string; locked?: boolean; redirect?: string };

            if (json.locked) {
                // Signed out everywhere: leave the app with a full page load, so nothing of the signed-in app stays
                // on screen. The sign-in page shows why. The panel below only shows if the redirect is blocked.
                setLocked({ message: json.message ?? 'You have been signed out.', redirect: json.redirect ?? '/login' });
                window.location.replace(json.redirect ?? '/login');

                return;
            }

            if (!response.ok || !json.reply) {
                throw new Error(json.message ?? 'The assistant could not answer just now. Please try again.');
            }

            setTurns((current) => [...current, { role: 'assistant', content: json.reply as string, html: json.html ?? null, mode: json.mode_label ?? null }]);
        } catch (exception) {
            setError(exception instanceof Error ? exception.message : 'The assistant could not answer just now.');
        } finally {
            setSending(false);
        }
    }

    async function clear() {
        await fetch('/assistant/messages', { method: 'DELETE', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() } });
        setTurns([]);
    }

    if (locked) {
        // Not the app shell: the person is no longer signed in.
        return (
            <main className="flex min-h-screen items-center justify-center bg-slate-950 p-6 text-slate-100">
                <div role="alert" className="flex max-w-lg flex-col gap-4 rounded-2xl border border-rose-800 bg-slate-900 p-6">
                    <h1 className="text-xl font-semibold">You have been signed out</h1>
                    <p className="text-sm text-slate-300">{locked.message}</p>
                    <a href={locked.redirect} className="self-start rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400">Go to sign in</a>
                </div>
            </main>
        );
    }

    return (
        <AppLayout title="Assistant">
            <Panel title="Ask the assistant" description={`Ask how to do something on the site, about your account and teams${figures.length > 0 ? `, or for figures such as ${figures.join(', ').toLowerCase()}` : ''}. Up to ${dailyLimit} messages a day.`}>
                <p className="rounded-lg border border-amber-800/60 bg-amber-950/40 px-3 py-2 text-xs text-amber-100">
                    The assistant never handles passwords, sign-in codes, keys or other people’s details. Asking for them signs you out at once and alerts the administrators.
                </p>
                {staffModes.length > 0 && (
                    <p className="text-xs text-slate-400">
                        Staff modes for you: {staffModes.join(' and ')}. Questions about running the site or about figures are answered in the matching mode automatically; each answer shows which mode it came from.
                    </p>
                )}

                <div role="log" aria-live="polite" aria-label="Conversation" className="flex max-h-[60vh] min-h-48 flex-col gap-3 overflow-y-auto rounded-xl border border-slate-800 bg-slate-950 p-4">
                    {turns.length === 0 && <p className="m-auto text-sm text-slate-400">Try “How do I invite someone to my team?”</p>}
                    {turns.map((turn, index) => (
                        <div key={index} className={`max-w-[85%] rounded-2xl px-4 py-2.5 text-sm leading-relaxed ${turn.role === 'user' ? 'self-end whitespace-pre-line bg-emerald-500/15 text-emerald-50' : 'self-start bg-slate-800 text-slate-100'}`}>
                            <span className="sr-only">{turn.role === 'user' ? 'You:' : 'Assistant:'} </span>
                            {turn.role === 'assistant' && turn.mode && turn.mode !== 'Help' && (
                                <span className="mb-1 block text-xs font-medium uppercase tracking-wide text-emerald-300">{turn.mode}</span>
                            )}
                            {turn.role === 'assistant' && turn.html
                                ? <div className="assistant-reply" dangerouslySetInnerHTML={{ __html: turn.html }} />
                                : turn.content}
                        </div>
                    ))}
                    {sending && <p className="self-start text-sm text-slate-400">The assistant is thinking…</p>}
                    <div ref={end} />
                </div>

                <StatusMessage tone="error" message={error} />

                <form onSubmit={send} className="flex flex-col gap-2 sm:flex-row sm:items-end">
                    <div className="flex flex-1 flex-col gap-1.5">
                        <label htmlFor="assistant-message" className="text-sm font-medium text-slate-200">Your message</label>
                        <textarea id="assistant-message" rows={2} maxLength={2000} value={message} onChange={(event) => setMessage(event.target.value)}
                            onKeyDown={(event) => {
                                // Enter sends; Shift+Enter starts a new line.
                                if (event.key === 'Enter' && !event.shiftKey) {
                                    event.preventDefault();
                                    void send(event);
                                }
                            }}
                            className="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-emerald-500 focus:outline-none" />
                    </div>
                    <div className="flex gap-2">
                        <Button disabled={sending || message.trim() === ''}>{sending ? 'Sending…' : 'Send'}</Button>
                        {turns.length > 0 && <Button type="button" variant="secondary" onClick={() => void clear()}>New conversation</Button>}
                    </div>
                </form>
            </Panel>
        </AppLayout>
    );
}
