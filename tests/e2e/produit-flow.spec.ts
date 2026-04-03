import { expect, test } from '@playwright/test';
import {
    escapeRegExp,
    getVisibleSearchInput,
    login,
    randomDigits,
    registerCleanup,
} from './helpers';

const E2E_PRODUCT_PREFIX = 'E2E Produit';

test.setTimeout(180_000);

registerCleanup('/produits', E2E_PRODUCT_PREFIX);

test('login + create product + verify list', async ({ page }) => {
    const unique = `${Date.now()}-${randomDigits(3)}`;
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
