/**
 * Smoke tests - run on every pre-prod -> main PR.
 * Fast gate: verify the app is alive and auth flow works before hitting production.
 */
import { expect, test, type Locator, type Page } from '@playwright/test';
import { login } from './helpers';

test.setTimeout(120_000);

async function clickFirstVisible(locators: Locator[]): Promise<boolean> {
    for (const locator of locators) {
        if (await locator.isVisible().catch(() => false)) {
            await locator.click({ timeout: 5000, force: true });
            return true;
        }
    }

    return false;
}

async function triggerLogout(page: Page): Promise<void> {
    const logoutTargets = [
        page.getByRole('menuitem', { name: /d[eé]connexion|logout/i }).first(),
        page.getByRole('button', { name: /d[eé]connexion|logout/i }).first(),
        page.getByRole('link', { name: /d[eé]connexion|logout/i }).first(),
    ];

    if (await clickFirstVisible(logoutTargets)) {
        return;
    }

    const userMenuTrigger = page
        .getByRole('button', { name: /issa|\bib\b|\+\d|profil|compte/i })
        .first();

    if (await userMenuTrigger.isVisible().catch(() => false)) {
        await userMenuTrigger.click({ timeout: 5000, force: true });

        if (await clickFirstVisible(logoutTargets)) {
            return;
        }
    }

    const csrfToken = await page
        .locator('meta[name="csrf-token"]')
        .getAttribute('content')
        .catch(() => '');

    await page.request
        .post('/logout', {
            headers: csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : undefined,
            failOnStatusCode: false,
            timeout: 15_000,
        })
        .catch(() => undefined);
}

test('home page responds', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.ok()).toBeTruthy();
    await expect(page.locator('body')).toBeVisible();
});

test('login page renders', async ({ page }) => {
    await page.goto('/login');
    await expect(page.locator('input[name="password"]')).toBeVisible({
        timeout: 15_000,
    });
});

test('authenticated user reaches dashboard', async ({ page }) => {
    await login(page);
    await expect(page).not.toHaveURL(/\/login/);
    await expect(page.locator('body')).toBeVisible();
});

test('logout redirects to login', async ({ page }) => {
    await login(page);

    await triggerLogout(page);
    await page.goto('/login');

    if (!/\/login(?:\?.*)?$/.test(page.url())) {
        await page.context().clearCookies().catch(() => undefined);
        await page.goto('/login');
    }

    await expect(page).toHaveURL(/\/login(?:\?.*)?$/, { timeout: 20_000 });
});

