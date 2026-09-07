import { defineConfig } from '@playwright/test';

// Aperçus UI sur le vrai bundle, avec données fictives et requêtes interceptées.
// Prérequis : npm run build. Aucun serveur Laravel ni accès à une base de données.
export default defineConfig({
    testDir: './tests/ui',
    fullyParallel: false,
    workers: 1,
    reporter: 'list',
    use: {
        browserName: 'chromium',
        viewport: { width: 390, height: 844 },
        hasTouch: true,
        serviceWorkers: 'block',
        screenshot: 'only-on-failure',
    },
});
