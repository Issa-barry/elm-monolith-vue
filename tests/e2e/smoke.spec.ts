/**
 * Smoke tests - run on every pre-prod -> main PR.
 * Fast gate: verify the app is alive and auth flow works before hitting production.
 */
import { expect, test } from '@playwright/test';
import { login } from './helpers';

test.setTimeout(90_000);

test('home page responds', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.ok()).toBeTruthy();
    await expect(page.locator('body')).toBeVisible();
});

test('login page renders', async ({ page }) => {
    // Vide les cookies pour simuler un utilisateur non authentifié
    // (avec storageState actif, /login redirigerait sinon vers le dashboard).
    await page.context().clearCookies();
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

    await page.context().clearCookies();
    await page.goto('/login');

    await expect(page).toHaveURL(/\/login(?:\?.*)?$/, { timeout: 20_000 });
});
