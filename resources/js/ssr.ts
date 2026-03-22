import Aura from '@primeuix/themes/aura';
import Lara from '@primeuix/themes/lara';
import Material from '@primeuix/themes/material';
import Nora from '@primeuix/themes/nora';
import { createInertiaApp } from '@inertiajs/vue3';
import createServer from '@inertiajs/vue3/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import PrimeVue from 'primevue/config';
import { createSSRApp, DefineComponent, h } from 'vue';
import { renderToString } from 'vue/server-renderer';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
const primeVueThemeName = (import.meta.env.VITE_PRIMEVUE_THEME || 'aura').toLowerCase();
const primeVuePreset =
    primeVueThemeName === 'lara'
        ? Lara
        : primeVueThemeName === 'material'
          ? Material
          : primeVueThemeName === 'nora'
            ? Nora
            : Aura;

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
