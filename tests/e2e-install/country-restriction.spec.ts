/**
 * Non-régression pays (cf. demande, section 21) : /install ne propose que Guinée (par défaut)
 * et Sierra Leone, tandis que /login (et le reste de l'application) continue de proposer la
 * liste complète — la restriction est propre à /install, jamais globale au composant partagé
 * PhoneCountryInput.vue.
 *
 * Run: npx playwright test --config=playwright.install.config.ts tests/e2e-install/country-restriction.spec.ts
 */
import { expect, test } from '@playwright/test';

test.setTimeout(60_000);

test('install ne propose que Guinée (par défaut) et Sierra Leone', async ({ page }) => {
    await page.goto('/install');
    await page.locator('#org-nom').fill('Eau la maman E2E Pays');
    await page.getByRole('button', { name: /suivant/i }).click();

    const countrySelect = page.locator('#admin-telephone').locator('..').getByRole('combobox');
    await expect(countrySelect).toContainText('+224'); // Guinée sélectionnée par défaut

    await countrySelect.click();
    const options = page.getByRole('option');
    await expect(options).toHaveCount(2);
    await expect(page.getByRole('option', { name: /guinée/i })).toBeVisible();
    await expect(page.getByRole('option', { name: /sierra leone/i })).toBeVisible();
    await expect(page.getByRole('option', { name: /sénégal|france|mali/i })).toHaveCount(0);
});

test('login continue de proposer la liste complète de pays (non-régression)', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto('/login');
    await page.waitForSelector('input[name="password"]', { timeout: 20_000 });

    await page.getByRole('combobox').first().click();
    const options = page.getByRole('option');
    await expect(options).toHaveCount(11); // DEFAULT_PAYS complet (PhoneCountryInput.vue)
    await expect(page.getByRole('option', { name: /sénégal/i })).toBeVisible();
    await expect(page.getByRole('option', { name: /france/i })).toBeVisible();
});
