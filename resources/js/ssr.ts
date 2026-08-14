import { createInertiaApp } from '@inertiajs/vue3';
import createServer from '@inertiajs/vue3/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import PrimeVue from 'primevue/config';
import { createSSRApp, DefineComponent, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { getPrimeVueThemePreset } from './lib/primevue-theme';
import type { ThemeSharedProps } from './types/theme';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

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
            setup: ({ App, props, plugin }) => {
                // Même source qu'app.ts (cf. docs/theming.md) : le thème
                // global vient des props Inertia partagées par le serveur,
                // jamais de VITE_*/localStorage.
                const initialTheme = (
                    props.initialPage.props as { theme: ThemeSharedProps }
                ).theme.active;
                const { preset: primeVuePreset } = getPrimeVueThemePreset(
                    initialTheme.preset,
                );

                return createSSRApp({ render: () => h(App, props) })
                    .use(plugin)
                    .use(PrimeVue, {
                        theme: {
                            preset: primeVuePreset,
                            options: {
                                darkModeSelector: '.dark',
                            },
                        },
                    });
            },
        }),
    { cluster: true },
);
