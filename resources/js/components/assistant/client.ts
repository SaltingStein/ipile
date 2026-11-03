/**
 * Requests to the built-in site assistant's JSON and streaming endpoints (AssistantController), for the chat bubble.
 * The server screens, logs and checks every answer; this file only moves text back and forth.
 */

export interface AssistantTurn {
    role: 'user' | 'assistant';
    content: string;
    /** The assistant's Markdown answer as safe HTML, rendered by the server. */
    html?: string | null;
    /** Which mode answered (assistant turns only), e.g. "Site guidance". */
    mode?: string | null;
}

export interface AssistantConversationSummary {
    id: string;
    title: string;
    updated_at: string | null;
}

/** The assistant will not answer: the reason to show, and where to go when the person was signed out. */
export class AssistantStopped extends Error {
    constructor(message: string, public readonly redirect: string | null = null) {
        super(message);
    }
}

interface StreamHandlers {
    onStart: (conversationId: string, modeLabel: string) => void;
    /** The answer so far, already checked by the server. Replaces, never appends. */
    onText: (text: string) => void;
}

export function xsrfToken(): string {
    return decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '');
}

function headers(json = false): HeadersInit {
    return { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), ...(json ? { 'Content-Type': 'application/json' } : {}) };
}

/**
 * Send a message and stream the answer. Resolves with the final answer, or throws AssistantStopped.
 */
export async function streamMessage(message: string, conversationId: string | null, handlers: StreamHandlers): Promise<{ conversationId: string; turn: AssistantTurn }> {
    const response = await fetch('/assistant/messages/stream', {
        method: 'POST',
        headers: { ...headers(true), Accept: 'text/event-stream, application/json' },
        body: JSON.stringify({ message, conversation_id: conversationId, channel: 'bubble' }),
    });

    if (!response.ok || !response.body || !(response.headers.get('Content-Type') ?? '').includes('text/event-stream')) {
        const json = (await response.json().catch(() => ({}))) as { message?: string; locked?: boolean; redirect?: string };
        throw new AssistantStopped(json.message ?? 'The assistant could not answer just now. Please try again.', json.locked ? (json.redirect ?? '/login') : null);
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let conversation = conversationId ?? '';

    for (;;) {
        const { value, done } = await reader.read();
        buffer += decoder.decode(value, { stream: !done });

        let boundary = buffer.indexOf('\n\n');
        while (boundary !== -1) {
            const block = buffer.slice(0, boundary);
            buffer = buffer.slice(boundary + 2);
            boundary = buffer.indexOf('\n\n');

            const event = block.match(/^event: (.+)$/m)?.[1];
            const data = JSON.parse(block.match(/^data: (.+)$/m)?.[1] ?? '{}') as Record<string, string>;

            if (event === 'start') {
                conversation = data.conversation_id;
                handlers.onStart(conversation, data.mode_label);
            } else if (event === 'text') {
                handlers.onText(data.text);
            } else if (event === 'done') {
                return { conversationId: data.conversation_id, turn: { role: 'assistant', content: '', html: data.html, mode: data.mode_label } };
            } else if (event === 'failed') {
                throw new AssistantStopped(data.message);
            }
        }

        if (done) {
            throw new AssistantStopped('The assistant could not answer just now. Please try again.');
        }
    }
}

export async function recentConversations(): Promise<AssistantConversationSummary[]> {
    const response = await fetch('/assistant/conversations', { headers: headers() });

    return response.ok ? ((await response.json()) as { conversations: AssistantConversationSummary[] }).conversations : [];
}

export async function loadConversation(id: string): Promise<AssistantTurn[]> {
    const response = await fetch(`/assistant/conversations/${encodeURIComponent(id)}`, { headers: headers() });

    return response.ok ? ((await response.json()) as { messages: AssistantTurn[] }).messages : [];
}

export async function deleteConversation(id: string): Promise<void> {
    await fetch(`/assistant/conversations/${encodeURIComponent(id)}`, { method: 'DELETE', headers: headers() });
}
