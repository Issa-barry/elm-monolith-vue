import { createInertiaApp } from '@inertiajs/vue3';
import createServer from '@inertiajs/vue3/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import PrimeVue from 'primevue/config';
import { createSSRApp, DefineComponent, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { loadPrimeVuePreset } from './lib/primevue-theme';
import type { ThemeSharedProps } from './types/theme';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createServer(
    async (page) => {
        // Même source qu'app.ts (cf. docs/theming.md) : le thème global vient
        // des props Inertia partagées par le serveur, jamais de
        // VITE_*/localStorage. Le preset est prêt avant le rendu.
        const primeVuePreset = await loadPrimeVuePreset(
            (page.props as unknown as { theme: ThemeSharedProps }).theme.active
                .preset,
        );

        return createInertiaApp({
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
        });
    },
    { cluster: true },
);
