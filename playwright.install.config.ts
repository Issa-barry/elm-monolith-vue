import { defineConfig, devices } from '@playwright/test';

// Config Playwright DÉDIÉE aux scénarios /install (cf. tests/e2e-install/) — délibérément
// séparée de playwright.config.ts : le harnais e2e "normal" suppose une base déjà migrée ET
// seedée (npm run e2e:db:reset) plus une session pré-authentifiée partagée (globalSetup,
// storageState: '.auth/user.json'). /install exige l'inverse : aucune organisation en base et
// un navigateur non authentifié. Port distinct (8081) pour ne jamais entrer en conflit avec le
// serveur e2e "normal" (8080) si les deux tournaient en parallèle.
const baseURL = 'http://127.0.0.1:8081';

export default defineConfig({
    testDir: './tests/e2e-install',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: 'list',
    expect: {
        timeout: 15_000,
    },
    use: {
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8081 --env=e2e',
        url: baseURL,
        reuseExistingServer: false,
        timeout: 120 * 1000,
        stdout: 'pipe',
        stderr: 'pipe',
    },
});
