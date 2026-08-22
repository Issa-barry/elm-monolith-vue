import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    server: {
        host: '127.0.0.1',
        hmr: {
            host: '127.0.0.1',
        },
    },
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            ssr: 'resources/js/ssr.ts',
            refresh: true,
            // `npm run e2e:build` (cross-env APP_ENV=e2e vite build) régénère les
            // helpers Wayfinder avec des URLs absolues basées sur APP_URL=.env.e2e
            // (127.0.0.1:8080). Sans ce dossier de sortie dédié, ce build écrase
            // public/build/ — utilisé par le serveur de dev (port 8000) — et sert
            // ensuite des formulaires qui POSTent vers le port e2e au lieu du port
            // dev. Le pendant PHP est AppServiceProvider::boot() (Vite::useBuildDirectory).
            buildDirectory: process.env.APP_ENV === 'e2e' ? 'build-e2e' : 'build',
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
});

