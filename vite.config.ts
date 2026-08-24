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
            // Les helpers Wayfinder (resources/js/routes, resources/js/actions) sont des
            // fichiers PARTAGÉS, non isolés par environnement (contrairement à buildDirectory
            // ci-dessus) — et `npm run dev` tourne en continu en local pendant qu'on lance
            // `npm run e2e:build` : son propre plugin Wayfinder régénère ces mêmes fichiers
            // avec l'APP_URL de dev (http://localhost:8000) dès qu'un fichier PHP surveillé
            // change, en pleine course avec la génération e2e (~3 min de build) — le bundle
            // e2e finissait avec un mélange incohérent d'URLs 8000/8080 selon l'ordre
            // d'arrivée, et le formulaire de connexion POSTait alors vers le mauvais serveur
            // (24/08/2026, aucun test E2E ne pouvait plus se connecter). On ne peut pas
            // supprimer cette fenêtre de course sans séparer les fichiers générés par
            // environnement (changement plus large, hors périmètre) : à la place,
            // `npm run e2e:build` (package.json) génère explicitement avec --env=e2e AVANT
            // `vite build`, et ce plugin saute sa propre régénération pendant ce build précis
            // — la fenêtre de course résiduelle (lecture initiale du graphe de modules par
            // Rollup) tombe de ~3 min à quelques millisecondes.
            actions: process.env.APP_ENV !== 'e2e',
            routes: process.env.APP_ENV !== 'e2e',
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

