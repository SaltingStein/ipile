import { useEffect } from 'react';
import type { AssistantWidgetConfig } from '../types';

let loadedRevision: string | null = null;
let unloadTimer: ReturnType<typeof setTimeout> | undefined;

/**
 * Keep one embed across workspace visits. The provider has no teardown API, so leaving the workspace or
 * changing account/configuration reloads the document to discard its DOM, handlers and chat session.
 */
export default function AssistantWidget({ config }: { config: AssistantWidgetConfig | null }) {
    const revision = config?.revision;
    const baseUrl = config?.base_url;
    const integrationId = config?.integration_id;
    const claimEndpoint = config?.claim_endpoint;

    useEffect(() => {
        if (!revision || !baseUrl || !integrationId) return;

        clearTimeout(unloadTimer);
        if (loadedRevision !== null && loadedRevision !== revision) {
            window.location.reload();
            return;
        }

        if (loadedRevision === null) {
            const script = document.createElement('script');
            script.src = `${baseUrl}/embed.js`;
            script.async = true;
            script.dataset.bihubIntegration = integrationId;
            script.dataset.bihubBase = baseUrl;
            if (claimEndpoint) script.dataset.claimEndpoint = claimEndpoint;
            document.body.appendChild(script);
            loadedRevision = revision;
        }

        return () => {
            // A synchronous workspace remount cancels this; public/admin pages and logout unload the embed.
            unloadTimer = setTimeout(() => window.location.reload(), 0);
        };
    }, [revision, baseUrl, integrationId, claimEndpoint]);

    return null;
}
