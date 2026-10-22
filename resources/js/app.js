import { createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { loadGoogleAnalytics } from './analytics';

// The server sets data-theme on first load; keep it in step when an admin switches theme without a full reload.
router.on('navigate', (event) => {
    document.documentElement.dataset.theme = event.detail.page.props.theme;
    loadGoogleAnalytics(event.detail.page.props);
});

createInertiaApp({
    title: (title) => title,
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.tsx`,
            import.meta.glob('./pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        createRoot(el).render(createElement(App, props));
    },
});
