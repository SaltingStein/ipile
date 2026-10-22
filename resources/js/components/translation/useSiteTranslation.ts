import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import type { RefObject } from 'react';
import { PageTranslator } from './PageTranslator';
import type { Dictionary } from './PageTranslator';
import type { SiteLanguages } from '../../types';

const CHOICE_KEY = 'ipile:language';
const DICTIONARY_KEY = 'ipile:translations:';

/**
 * Translations already loaded in this tab, so moving between pages applies them before the first paint.
 */
const loaded = new Map<string, { version: string; strings: Dictionary }>();

function storedChoice(languages: SiteLanguages | null): string | null {
    try {
        const choice = window.localStorage.getItem(CHOICE_KEY);

        return choice && languages?.languages.some((language) => language.code === choice) ? choice : null;
    } catch {
        return null;
    }
}

function cachedDictionary(locale: string, version: string): Dictionary | null {
    const inMemory = loaded.get(locale);

    if (inMemory?.version === version) {
        return inMemory.strings;
    }

    try {
        const stored = JSON.parse(window.localStorage.getItem(DICTIONARY_KEY + locale) ?? 'null') as { version: string; strings: Dictionary } | null;

        if (stored?.version === version) {
            loaded.set(locale, stored);

            return stored.strings;
        }
    } catch {
        // Storage may be full or blocked; the list is fetched instead.
    }

    return null;
}

async function fetchDictionary(locale: string, version: string): Promise<Dictionary> {
    const response = await fetch(`/translations/${locale}?v=${encodeURIComponent(version)}`, { headers: { Accept: 'application/json' } });

    if (!response.ok) {
        throw new Error(String(response.status));
    }

    const json = (await response.json()) as { version: string; strings: Dictionary };
    loaded.set(locale, { version, strings: json.strings });

    try {
        window.localStorage.setItem(DICTIONARY_KEY + locale, JSON.stringify({ version, strings: json.strings }));
    } catch {
        // Not stored; it is fetched again next time.
    }

    return json.strings;
}

function reportMissing(locale: string, strings: string[]): void {
    const token = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '');

    void fetch('/translations/missing', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token },
        body: JSON.stringify({ locale, strings }),
        keepalive: true,
    }).catch(() => undefined);
}

/**
 * Translates `root` into the visitor's chosen language while site translation is on. Returns the chosen
 * language (null: the site's own) and a function to change it; the choice is remembered in this browser.
 */
export function useSiteTranslation(root: RefObject<HTMLElement>, languages: SiteLanguages | null): { language: string | null; setLanguage: (code: string | null) => void } {
    const [language, setLanguageState] = useState<string | null>(() => storedChoice(languages));
    const translator = useRef<PageTranslator | null>(null);
    const active = language !== null && languages !== null ? language : null;
    const version = languages?.version ?? '';
    const enabled = languages !== null;

    useLayoutEffect(() => {
        if (!root.current || !enabled) {
            return;
        }

        translator.current = new PageTranslator(root.current, reportMissing);

        return () => {
            translator.current?.disconnect();
            translator.current = null;
        };
    }, [root, enabled]);

    // Apply before paint when the translations are already here, so pages do not flash in English.
    useLayoutEffect(() => {
        if (!translator.current) {
            return;
        }

        if (active === null) {
            translator.current.setLanguage(null);

            return;
        }

        const cached = cachedDictionary(active, version);

        if (cached) {
            translator.current.setLanguage(active, cached);
        }
    }, [active, version]);

    useEffect(() => {
        if (active === null || cachedDictionary(active, version)) {
            return;
        }

        let cancelled = false;
        root.current?.setAttribute('aria-busy', 'true');
        fetchDictionary(active, version)
            .then((strings) => !cancelled && translator.current?.setLanguage(active, strings))
            .catch(() => undefined)
            .finally(() => root.current?.removeAttribute('aria-busy'));

        return () => {
            cancelled = true;
        };
    }, [active, version, root]);

    useEffect(() => {
        const details = languages?.languages.find((option) => option.code === active);
        document.documentElement.lang = details?.code ?? languages?.source ?? 'en';
        root.current?.setAttribute('dir', details?.dir ?? 'ltr');
    }, [active, languages, root]);

    const setLanguage = useCallback((code: string | null) => {
        try {
            if (code === null) {
                window.localStorage.removeItem(CHOICE_KEY);
            } else {
                window.localStorage.setItem(CHOICE_KEY, code);
            }
        } catch {
            // The choice lasts for this page only.
        }

        setLanguageState(code);
    }, []);

    return { language: active, setLanguage };
}
