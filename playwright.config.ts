import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8080';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    workers: process.env.CI ? 2 : 1,
    retries: process.env.CI ? 2 : 0,
    reporter: process.env.CI ? [['html'], ['github']] : 'html',
    expect: {
        timeout: 15_000,
    },
    use: {
        baseURL,
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
        command: 'php artisan serve --host=127.0.0.1 --port=8080',
        url: baseURL,
        reuseExistingServer: !process.env.CI,
        timeout: 120 * 1000,
    },
});

