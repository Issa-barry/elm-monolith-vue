import { createInertiaApp } from '@inertiajs/vue3';
import createServer from '@inertiajs/vue3/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import PrimeVue from 'primevue/config';
import { createSSRApp, DefineComponent, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import {
    getPrimeVueThemePreset,
    resolvePrimeVueThemeFromEnv,
} from './lib/primevue-theme';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
const initialPrimeVueTheme = resolvePrimeVueThemeFromEnv();
const { preset: primeVuePreset } = getPrimeVueThemePreset(initialPrimeVueTheme);

createServer(
    (page) =>
        createInertiaApp({
            page,
            render: renderToString,
            title: (title) => (title ? `${title} - ${appName}` : appName),
            resolve: (name) =>
                resolvePageComponent(
                    `./pages/${name}.vue`,
                    import.meta.glob<DefineComponent>('./pages/**/*.vue'),
                ),
            setup: ({ App, props, plugin }) =>
                createSSRApp({ render: () => h(App, props) })
                    .use(plugin)
                    .use(PrimeVue, {
                        theme: {
                            preset: primeVuePreset,
                            options: {
                                darkModeSelector: '.dark',
                            },
                        },
                    }),
        }),
    { cluster: true },
);
