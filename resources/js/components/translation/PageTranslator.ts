/**
 * Translates the text of a part of the page in place, using translations from the server (Admin → Languages).
 *
 * It changes the *value* of existing text nodes and a few attributes (never the structure), so React keeps
 * working: when React writes new text, the change is seen and translated again. Text with no translation yet
 * is reported (in batches) so it can be translated later. Nothing inside `translate="no"`, code, form inputs
 * or editors is touched.
 */
const SKIP = 'script, style, noscript, code, pre, kbd, samp, textarea, input, select, time, svg, [translate="no"], .notranslate, [contenteditable], [contenteditable="true"]';
const ATTRIBUTES = ['placeholder', 'title', 'aria-label', 'alt'];
const MAX_LENGTH = 500;
const REPORT_BATCH = 50;

export type Dictionary = Record<string, string>;

/**
 * "  Sign   in " → "Sign in": how texts are matched (the server cleans them the same way).
 */
export function normaliseText(text: string): string {
    return text.replace(/\s+/gu, ' ').trim();
}

function isTranslatable(text: string): boolean {
    return text.length > 0 && text.length <= MAX_LENGTH && /\p{L}/u.test(text);
}

export class PageTranslator {
    private locale: string | null = null;
    private dictionary: Dictionary = {};
    private readonly originals = new WeakMap<Node, string>();
    private readonly written = new WeakMap<Node, string>();
    private readonly attributeOriginals = new WeakMap<Element, Map<string, string>>();
    private readonly attributesWritten = new WeakMap<Element, Map<string, string>>();
    private readonly observer: MutationObserver;
    private readonly missing = new Set<string>();
    private readonly reported = new Set<string>();
    private reportTimer: number | undefined;

    constructor(private readonly root: HTMLElement, private readonly report: (locale: string, texts: string[]) => void) {
        this.observer = new MutationObserver((mutations) => this.onMutations(mutations));
        this.observer.observe(root, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ATTRIBUTES });
    }

    /**
     * Show the page in a language (null: the site's own language).
     */
    setLanguage(locale: string | null, dictionary: Dictionary = {}): void {
        this.locale = locale;
        this.dictionary = dictionary;
        this.missing.clear();
        this.walk(this.root, true);
    }

    disconnect(): void {
        this.observer.disconnect();
        window.clearTimeout(this.reportTimer);
    }

    private onMutations(mutations: MutationRecord[]): void {
        for (const mutation of mutations) {
            if (mutation.type === 'characterData') {
                this.translateText(mutation.target as Text, false);
            } else if (mutation.type === 'attributes' && mutation.attributeName) {
                this.translateAttribute(mutation.target as Element, mutation.attributeName, false);
            } else {
                mutation.addedNodes.forEach((node) => this.walk(node, false));
            }
        }
    }

    private walk(start: Node, force: boolean): void {
        if (start.nodeType === Node.TEXT_NODE) {
            this.translateText(start as Text, force);

            return;
        }

        if (!(start instanceof Element) || start.closest(SKIP)) {
            return;
        }

        const walker = document.createTreeWalker(start, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT, {
            acceptNode: (node) => (node instanceof Element && node.matches(SKIP) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT),
        });

        for (let node: Node | null = walker.currentNode; node; node = walker.nextNode()) {
            if (node.nodeType === Node.TEXT_NODE) {
                this.translateText(node as Text, force);
            } else {
                ATTRIBUTES.forEach((attribute) => this.translateAttribute(node as Element, attribute, force));
            }
        }
    }

    private translateText(node: Text, force: boolean): void {
        if (node.parentElement?.closest(SKIP)) {
            return;
        }

        const current = node.nodeValue ?? '';
        const next = this.translated(this.originals, this.written, node, current, force);

        if (next !== null && next !== current) {
            this.written.set(node, next);
            node.nodeValue = next;
        }
    }

    private translateAttribute(element: Element, attribute: string, force: boolean): void {
        const current = element.getAttribute(attribute);

        if (current === null || element.closest(SKIP)) {
            return;
        }

        const originals = this.mapFor(this.attributeOriginals, element);
        const written = this.mapFor(this.attributesWritten, element);
        const original = written.get(attribute) === current ? (force ? (originals.get(attribute) ?? current) : null) : current;

        if (original === null) {
            return;
        }

        originals.set(attribute, original);
        const next = this.translationOf(original);

        if (next !== current) {
            written.set(attribute, next);
            element.setAttribute(attribute, next);
        }
    }

    /**
     * The text to show, or null to leave the node alone (it already shows our translation).
     */
    private translated(originals: WeakMap<Node, string>, written: WeakMap<Node, string>, node: Node, current: string, force: boolean): string | null {
        let original: string;

        if (written.get(node) === current) {
            // Our own translation: only redo it when the language changes.
            if (!force) {
                return null;
            }

            original = originals.get(node) ?? current;
        } else {
            // New text, from React or the server.
            original = current;
            originals.set(node, original);
        }

        return this.translationOf(original);
    }

    /**
     * The text in the current language, keeping the spaces around it, or the original when there is none yet.
     */
    private translationOf(original: string): string {
        if (this.locale === null) {
            return original;
        }

        const key = normaliseText(original);

        if (!isTranslatable(key)) {
            return original;
        }

        const translation = this.dictionary[key];

        if (translation === undefined) {
            this.queueMissing(key);

            return original;
        }

        return (original.match(/^\s*/)?.[0] ?? '') + translation + (original.match(/\s*$/)?.[0] ?? '');
    }

    private queueMissing(text: string): void {
        const reportKey = `${this.locale}\u0000${text}`;

        if (this.reported.has(reportKey)) {
            return;
        }

        this.reported.add(reportKey);
        this.missing.add(text);
        window.clearTimeout(this.reportTimer);
        this.reportTimer = window.setTimeout(() => this.flush(), 1500);
    }

    private flush(): void {
        if (this.locale === null || this.missing.size === 0) {
            return;
        }

        const texts = [...this.missing];
        this.missing.clear();

        for (let start = 0; start < texts.length; start += REPORT_BATCH) {
            this.report(this.locale, texts.slice(start, start + REPORT_BATCH));
        }
    }

    private mapFor(store: WeakMap<Element, Map<string, string>>, element: Element): Map<string, string> {
        let map = store.get(element);

        if (!map) {
            map = new Map();
            store.set(element, map);
        }

        return map;
    }
}
