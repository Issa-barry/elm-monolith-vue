import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8080';

export default defineConfig({
    testDir: './tests/e2e',
    globalSetup: './tests/e2e/global-setup.ts',
    fullyParallel: true,
    workers: process.env.CI ? 2 : 1,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? [['html'], ['github']] : 'html',
    expect: {
        timeout: 15_000,
    },
    use: {
        baseURL,
        storageState: '.auth/user.json',
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    webServer: {
        // --env=e2e : charge .env.e2e (BDD elm_monolithe_e2e), jamais la BDD de
        // dev — sans ce flag Laravel retombe sur .env et les tests écrivent
        // dans la base de dev (cf. `npm run e2e:db:reset` dans package.json).
        command: 'php artisan serve --host=127.0.0.1 --port=8080 --env=e2e',
        url: baseURL,
        reuseExistingServer: !process.env.CI,
        timeout: 120 * 1000,
        // 'pipe' plutôt que le défaut ('ignore') : si le boot échoue (config
        // invalide, extension PHP manquante, migration non appliquée...), le
        // timeout webServer ne dit que "Timed out" sans raison — avec 'pipe',
        // la vraie sortie de `php artisan serve` (erreur fatale PHP incluse)
        // apparaît dans les logs CI au lieu de rester invisible.
        stdout: 'pipe',
        stderr: 'pipe',
    },
});

