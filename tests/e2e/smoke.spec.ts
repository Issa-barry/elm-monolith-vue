/**
 * Smoke tests - run on every pre-prod -> main PR.
 * Fast gate: verify the app is alive and auth flow works before hitting production.
 */
import { expect, test } from '@playwright/test';
import { login } from './helpers';

test.setTimeout(90_000);

test('home page redirects unauthenticated users to login', async ({ page }) => {
    await page.context().clearCookies();
    const response = await page.goto('/');
    expect(response?.ok()).toBeTruthy();
    await expect(page).toHaveURL(/\/login(?:\?.*)?$/, { timeout: 15_000 });
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

test('login link on a guest page uses the correct APP_URL (regression guard)', async ({
    page,
}) => {
    // Clique un vrai lien généré côté client (login() de Wayfinder) plutôt que
    // de naviguer directement vers /login : ça exerce l'URL absolue générée au
    // build, celle qui peut être fausse si APP_URL n'est pas correcte au
    // moment du build (cf. incident .com du 2026-07-11 où /login pointait
    // vers 127.0.0.1:8000). La page d'accueil publique a été retirée du
    // back-office (contenu désormais porté par elm-vitrine) ; /forgot-password
    // reste une page invité qui utilise le même helper login().
    await page.context().clearCookies();
    await page.goto('/forgot-password');
    await page.getByRole('link', { name: /la connexion/i }).click();
    await expect(page).toHaveURL(/\/login(?:\?.*)?$/, { timeout: 15_000 });
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
