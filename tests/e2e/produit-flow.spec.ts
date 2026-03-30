import { expect, test } from '@playwright/test';
import {
    cleanupRowsByPrefix,
    escapeRegExp,
    getVisibleSearchInput,
    login,
} from './helpers';

const E2E_PRODUCT_PREFIX = 'E2E Produit';

test.setTimeout(180_000);

test.afterEach(async ({ browser }) => {
    try {
        const context = await browser.newContext();
        try {
            const p = await context.newPage();
            await cleanupRowsByPrefix(p, '/produits', E2E_PRODUCT_PREFIX);
        } finally {
            await context.close().catch(() => undefined);
        }
    } catch (e) {
        console.warn('E2E cleanup warning (produits):', e);
    }
});

test('login + create product + verify list', async ({ page }) => {
    const unique = `${Date.now()}-${Math.floor(Math.random() * 1000)}`;
    const productName = `${E2E_PRODUCT_PREFIX} ${unique}`;
    const supplierCode = `E2E-${unique}`;

    await login(page);

    await page.goto('/produits/create');
    await expect(
        page.getByRole('heading', { name: /nouveau produit/i }),
    ).toBeVisible();

    await page.locator('#nom').fill(productName);
    await page.locator('#code_fournisseur').fill(supplierCode);
    await page.getByRole('button', { name: /^enregistrer$/i }).click();

    await expect(page).toHaveURL(/\/produits$/);

    const searchInput = getVisibleSearchInput(page);
    await searchInput.fill(productName);

    const productRow = page
        .locator('tbody tr', {
            hasText: new RegExp(escapeRegExp(productName), 'i'),
        })
        .first();
    await expect(productRow).toBeVisible();
});
