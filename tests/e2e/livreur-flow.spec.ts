import { expect, test } from '@playwright/test';
import { cleanupRowsByPrefix, escapeRegExp, getVisibleSearchInput, login, openRowActions } from './helpers';

const E2E_LIVREUR_NAME_PREFIX = 'e2elivflow';

test.setTimeout(120_000);

function randomDigits(length: number): string {
    const max = 10 ** length;
    return `${Math.floor(Math.random() * max)}`.padStart(length, '0');
}

test.afterEach(async ({ browser }) => {
    const context = await browser.newContext();

    try {
        const cleanupPage = await context.newPage();
        await cleanupRowsByPrefix(cleanupPage, '/livreurs', E2E_LIVREUR_NAME_PREFIX);
    } catch (error) {
        console.warn('E2E cleanup warning (livreurs):', error);
    } finally {
        await context.close().catch(() => undefined);
    }
});

test('login + create livreur + update status + verify list', async ({ page }) => {
    const unique = `${Date.now()}-${Math.floor(Math.random() * 1000)}`;
    const prenom = `${E2E_LIVREUR_NAME_PREFIX}${unique.slice(-4)}`;
    const nom = `Flow${unique.slice(-4)}`;
    const telephone = `6${randomDigits(8)}`;

    await login(page);

    await page.goto('/livreurs/create');
    await expect(page).toHaveURL(/\/livreurs\/create$/);

    await page.locator('#prenom').fill(prenom);
    await page.locator('#nom').fill(nom);
    await page.locator('#ville').fill('Conakry');
    await page.locator('#adresse').fill('E2E Address');
    await page.locator('#telephone').fill(telephone);

    await page.locator('#livreur-form button[type="submit"]:visible').first().click();

    await expect(page).toHaveURL(/\/livreurs$/);

    const searchInput = getVisibleSearchInput(page);
    await searchInput.fill(prenom);

    const row = page.locator('tbody tr', {
        hasText: new RegExp(escapeRegExp(prenom), 'i'),
    }).first();
    await expect(row).toBeVisible();

    await openRowActions(row);
    await page.getByRole('menuitem', { name: /modifier/i }).first().click();

    await expect(page).toHaveURL(/\/livreurs\/\d+\/edit$/);

    await page.locator('label[for="is_active"]').first().click();
    await page.locator('#livreur-form button[type="submit"]:visible').first().click();

    await expect(page).toHaveURL(/\/livreurs$/);

    const updatedSearchInput = getVisibleSearchInput(page);
    await updatedSearchInput.fill(prenom);

    const updatedRow = page.locator('tbody tr', {
        hasText: new RegExp(escapeRegExp(prenom), 'i'),
    }).first();

    await expect(updatedRow).toBeVisible();
    await expect(updatedRow).toContainText(/inactif/i);
});
