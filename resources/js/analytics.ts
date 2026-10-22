import { hasConsent } from './components/ConsentGate';
import type { SharedPageProps } from './types';

declare global {
    interface Window {
        dataLayer?: unknown[];
        gtag?: (...args: unknown[]) => void;
    }
}

let loadedMeasurementId: string | null = null;

/**
 * Load Google Analytics (configured in Admin → Integrations) once the visitor has accepted analytics cookies.
 * Called on every Inertia navigation, including the first render. Withdrawing consent reloads the page, which unloads it.
 * Later page views are recorded by GA4 enhanced measurement (browser history changes), so none are sent from here.
 */
export function loadGoogleAnalytics(props: SharedPageProps): void {
    const measurementId = props.analytics?.google_measurement_id;

    if (!measurementId || loadedMeasurementId !== null || !hasConsent(props.consent, 'analytics')) {
        return;
    }

    loadedMeasurementId = measurementId;

    const script = document.createElement('script');
    script.async = true;
    script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(measurementId)}`;
    document.head.appendChild(script);

    window.dataLayer = window.dataLayer ?? [];
    // gtag.js reads the `arguments` object, not an array, so this must stay a classic function.
    window.gtag = function gtag() {
        window.dataLayer!.push(arguments);
    };
    window.gtag('js', new Date());
    window.gtag('config', measurementId);
}
