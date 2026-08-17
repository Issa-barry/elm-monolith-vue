import { defineConfig, devices } from '@playwright/test';

// Config Playwright DÉDIÉE aux scénarios /install (cf. tests/e2e-install/) — délibérément
// séparée de playwright.config.ts : le harnais e2e "normal" suppose une base déjà migrée ET
// seedée (npm run e2e:db:reset) plus une session pré-authentifiée partagée (globalSetup,
// storageState: '.auth/user.json'). /install exige l'inverse : aucune organisation en base et
// un navigateur non authentifié. Port 8080 : identique à APP_URL dans .env.e2e — les actions
// Wayfinder compilées dans public/build embarquent une URL absolue figée à ce port au moment du
// build (cf. commentaire de tests/e2e/global-setup.ts sur cet incident déjà rencontré) ; servir
// sur un autre port casse silencieusement les requêtes qui utilisent ces URLs absolues (login
// notamment). Jamais lancé en même temps que le serveur e2e "normal" (même port, par design).
//
// Pas de bloc `webServer` ici (contrairement à playwright.config.ts) : sur cette machine,
// `php artisan serve` démarre correctement mais répond très lentement à froid (~2.5-3s/requête,
// pas d'opcache persistant entre requêtes, I/O disque Windows) — largement dans les clous d'un
// usage normal, mais le health-check interne de Playwright (polling rapproché pendant le
// démarrage) s'y bloque et finit en "Timed out waiting ... from config.webServer" alors que le
// serveur répond bel et bien. Le serveur est donc démarré/attendu manuellement (script npm
// e2e-install, cf. package.json) avant `playwright test`, et cette config se contente de pointer
// baseURL dessus.
const baseURL = 'http://127.0.0.1:8080';

export default defineConfig({
    testDir: './tests/e2e-install',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: 'list',
    timeout: 60_000,
    expect: {
        timeout: 20_000,
    },
    use: {
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        actionTimeout: 20_000,
        navigationTimeout: 30_000,
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
