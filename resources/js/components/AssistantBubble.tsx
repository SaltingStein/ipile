import { useEffect, useId, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { usePage } from '@inertiajs/react';
import type { SharedPageProps } from '../types';
import { AssistantStopped, deleteConversation, loadConversation, recentConversations, streamMessage } from './assistant/client';
import type { AssistantConversationSummary, AssistantTurn } from './assistant/client';

/**
 * The built-in site assistant as a chat bubble on every signed-in page (CORE-911). Shown only while the assistant is
 * on and the person may use it (the shared `assistantEnabled` prop), and not on the assistant's own page. Answers are
 * streamed as plain text and replaced by the server's safe HTML when they finish.
 */
export default function AssistantBubble() {
    const { assistantEnabled = false } = usePage<SharedPageProps>().props;
    const { url } = usePage();
    const panelId = useId();
    const [open, setOpen] = useState(false);
    const [view, setView] = useState<'chat' | 'recent'>('chat');
    const [turns, setTurns] = useState<AssistantTurn[]>([]);
    const [conversationId, setConversationId] = useState<string | null>(null);
    const [recent, setRecent] = useState<AssistantConversationSummary[]>([]);
    const [message, setMessage] = useState('');
    const [streaming, setStreaming] = useState<string | null>(null);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const toggle = useRef<HTMLButtonElement>(null);
    const input = useRef<HTMLTextAreaElement>(null);
    const end = useRef<HTMLDivElement>(null);

    useEffect(() => end.current?.scrollIntoView({ block: 'end' }), [turns, streaming]);
    useEffect(() => {
        if (open && view === 'chat') {
            input.current?.focus();
        }
    }, [open, view]);

    if (!assistantEnabled || url.startsWith('/assistant')) {
        return null;
    }

    function close() {
        setOpen(false);
        toggle.current?.focus();
    }

    function newChat() {
        setTurns([]);
        setConversationId(null);
        setError(null);
        setView('chat');
    }

    async function showRecent() {
        setView('recent');
        setRecent(await recentConversations());
    }

    async function openConversation(id: string) {
        setTurns(await loadConversation(id));
        setConversationId(id);
        setError(null);
        setView('chat');
    }

    async function removeConversation(id: string) {
        await deleteConversation(id);
        setRecent((current) => current.filter((conversation) => conversation.id !== id));

        if (id === conversationId) {
            newChat();
            setView('recent');
        }
    }

    async function send(event: FormEvent) {
        event.preventDefault();
        const text = message.trim();

        if (text === '' || sending) {
            return;
        }

        setSending(true);
        setError(null);
        setMessage('');
        setTurns((current) => [...current, { role: 'user', content: text }]);

        try {
            const result = await streamMessage(text, conversationId, {
                onStart: (id) => setConversationId(id),
                onText: (partial) => setStreaming(partial),
            });
            setConversationId(result.conversationId);
            setTurns((current) => [...current, result.turn]);
        } catch (exception) {
            if (exception instanceof AssistantStopped && exception.redirect) {
                // Signed out everywhere: leave the app with a full page load; the sign-in page shows why.
                window.location.replace(exception.redirect);

                return;
            }

            setError(exception instanceof Error ? exception.message : 'The assistant could not answer just now.');
        } finally {
            setStreaming(null);
            setSending(false);
        }
    }

    return (
        <div className="fixed bottom-5 right-5 z-40 flex flex-col items-end gap-3 print:hidden">
            {open && (
                <section id={panelId} role="dialog" aria-label="Assistant" aria-modal="false"
                    onKeyDown={(event) => event.key === 'Escape' && close()}
                    className="flex h-[min(36rem,calc(100vh-7rem))] w-[min(24rem,calc(100vw-2.5rem))] flex-col overflow-hidden rounded-2xl border border-slate-700 bg-slate-900 text-slate-100 shadow-2xl shadow-slate-950/50">
                    <header className="flex items-center gap-2 border-b border-slate-800 px-4 py-3">
                        <h2 className="flex-1 text-sm font-semibold">Assistant</h2>
                        <button type="button" onClick={() => void (view === 'recent' ? setView('chat') : showRecent())}
                            className="rounded-md px-2 py-1 text-xs text-slate-300 hover:bg-slate-800 hover:text-slate-100">
                            {view === 'recent' ? 'Back to chat' : 'Recent chats'}
                        </button>
                        <button type="button" onClick={newChat} className="rounded-md px-2 py-1 text-xs text-slate-300 hover:bg-slate-800 hover:text-slate-100">New chat</button>
                        <button type="button" onClick={close} aria-label="Close the assistant" className="rounded-md px-2 py-1 text-slate-400 hover:bg-slate-800 hover:text-slate-100">✕</button>
                    </header>

                    {view === 'recent' ? (
                        <ul className="flex flex-1 flex-col gap-1 overflow-y-auto p-3" aria-label="Recent chats">
                            {recent.length === 0 && <li className="p-3 text-sm text-slate-400">No chats yet.</li>}
                            {recent.map((conversation) => (
                                <li key={conversation.id} className="flex items-center gap-2 rounded-lg hover:bg-slate-800">
                                    <button type="button" onClick={() => void openConversation(conversation.id)} className="flex-1 truncate px-3 py-2 text-left text-sm">
                                        {conversation.title}
                                        {conversation.updated_at && <span className="block text-xs text-slate-400">{new Date(conversation.updated_at).toLocaleString()}</span>}
                                    </button>
                                    <button type="button" onClick={() => void removeConversation(conversation.id)} aria-label={`Delete chat: ${conversation.title}`}
                                        className="px-3 py-2 text-xs text-slate-400 hover:text-rose-300">Delete</button>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <>
                            <div role="log" aria-live="polite" aria-label="Conversation" className="flex flex-1 flex-col gap-3 overflow-y-auto p-4">
                                {turns.length === 0 && !streaming && (
                                    <p className="m-auto text-center text-sm text-slate-400">
                                        Ask how to do something here, or about your account and teams. It never handles passwords, codes, keys or other people’s details.
                                    </p>
                                )}
                                {turns.map((turn, index) => (
                                    <div key={index} className={`max-w-[90%] rounded-2xl px-3 py-2 text-sm leading-relaxed ${turn.role === 'user' ? 'self-end whitespace-pre-line bg-emerald-500/15 text-emerald-50' : 'self-start bg-slate-800 text-slate-100'}`}>
                                        <span className="sr-only">{turn.role === 'user' ? 'You:' : 'Assistant:'} </span>
                                        {turn.role === 'assistant' && turn.mode && turn.mode !== 'Help' && (
                                            <span className="mb-1 block text-xs font-medium uppercase tracking-wide text-emerald-300">{turn.mode}</span>
                                        )}
                                        {turn.role === 'assistant' && turn.html
                                            ? <div className="assistant-reply" dangerouslySetInnerHTML={{ __html: turn.html }} />
                                            : turn.content}
                                    </div>
                                ))}
                                {sending && (
                                    <div className="max-w-[90%] self-start whitespace-pre-line rounded-2xl bg-slate-800 px-3 py-2 text-sm text-slate-100" aria-busy="true">
                                        {streaming || <span className="text-slate-400">The assistant is thinking…</span>}
                                    </div>
                                )}
                                <div ref={end} />
                            </div>

                            {error && <p role="alert" className="border-t border-slate-800 px-4 py-2 text-sm text-rose-300">{error}</p>}

                            <form onSubmit={send} className="flex items-end gap-2 border-t border-slate-800 p-3">
                                <label htmlFor={`${panelId}-message`} className="sr-only">Your message</label>
                                <textarea id={`${panelId}-message`} ref={input} rows={2} maxLength={2000} value={message} onChange={(event) => setMessage(event.target.value)}
                                    onKeyDown={(event) => {
                                        // Enter sends; Shift+Enter starts a new line.
                                        if (event.key === 'Enter' && !event.shiftKey) {
                                            event.preventDefault();
                                            void send(event);
                                        }
                                    }}
                                    placeholder="Ask the assistant…"
                                    className="flex-1 resize-none rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 placeholder:text-slate-500 focus:border-emerald-500 focus:outline-none" />
                                <button type="submit" disabled={sending || message.trim() === ''}
                                    className="rounded-lg bg-emerald-500 px-3 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:opacity-50">
                                    {sending ? '…' : 'Send'}
                                </button>
                            </form>
                        </>
                    )}
                </section>
            )}

            <button ref={toggle} type="button" onClick={() => (open ? close() : setOpen(true))} aria-expanded={open} aria-controls={panelId}
                aria-label={open ? 'Close the assistant' : 'Open the assistant'}
                className="flex size-14 items-center justify-center rounded-full bg-emerald-500 text-2xl text-slate-950 shadow-lg shadow-emerald-500/30 hover:bg-emerald-400 focus:outline-none focus-visible:ring-4 focus-visible:ring-emerald-300/50">
                <span aria-hidden="true">{open ? '✕' : '💬'}</span>
            </button>
        </div>
    );
}
