/**
 * Smoke tests — run on every pre-prod → main PR.
 * Fast gate: verify the app is alive and auth flow works before hitting production.
 */
import { expect, test } from '@playwright/test';
import { login } from './helpers';

test.setTimeout(60_000);

test('home page responds', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.ok()).toBeTruthy();
    await expect(page.locator('body')).toBeVisible();
});

test('login page renders', async ({ page }) => {
    await page.goto('/login');
    await expect(page.locator('input[name="password"]')).toBeVisible({ timeout: 15_000 });
});

test('authenticated user reaches dashboard', async ({ page }) => {
    await login(page);
    await expect(page).not.toHaveURL(/\/login/);
    await expect(page.locator('body')).toBeVisible();
});

test('logout redirects to login', async ({ page }) => {
    await login(page);

    // Try sidebar/header logout button
    const logoutBtn = page.getByRole('button', { name: /d[eé]connexion|logout/i }).first();
    const logoutLink = page.getByRole('link', { name: /d[eé]connexion|logout/i }).first();

    if (await logoutBtn.isVisible().catch(() => false)) {
        await logoutBtn.click();
    } else if (await logoutLink.isVisible().catch(() => false)) {
        await logoutLink.click();
    } else {
        // Fallback: submit the hidden logout form or POST directly
        const csrfToken = await page
            .locator('meta[name="csrf-token"]')
            .getAttribute('content')
            .catch(() => '');
        await page.request.post('/logout', {
            headers: { 'X-CSRF-TOKEN': csrfToken ?? '' },
        });
        await page.goto('/login');
    }

    await expect(page).toHaveURL(/\/login/, { timeout: 15_000 });
});
