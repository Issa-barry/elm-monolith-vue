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

registerCleanup('/backoffice/produits', E2E_PRODUCT_PREFIX);

test('login + create product + verify list', async ({ page }) => {
    const unique = `${Date.now()}-${randomDigits(3)}`;
    const productName = `${E2E_PRODUCT_PREFIX} ${unique}`;
    const codeBarres = `E2E-${unique}`;

    await login(page);

    await page.goto('/backoffice/produits/create');
    await expect(
        page.getByRole('heading', { name: /nouveau produit/i }),
    ).toBeVisible();

    await page.locator('#nom').fill(productName);
    await page.locator('#code_barres').fill(codeBarres);
    // Le type par défaut du formulaire ("Matériel") exige un prix d'achat
    // (cf. ProduitType::requiredPrices()) — sans ça la création échoue en validation
    // et reste sur /produits/create.
    await page.locator('#prix_achat').fill('1000');
    await page.getByRole('button', { name: /^enregistrer$/i }).click();

    // Création → redirige vers la fiche du produit (pas la liste).
    await expect(page).toHaveURL(/\/produits\/[^/]+$/);
    await expect(
        page.getByRole('heading', { name: productName }),
    ).toBeVisible();

    await page.goto('/backoffice/produits');
    const searchInput = getVisibleSearchInput(page);
    await searchInput.fill(productName);
    await searchInput.press('Enter');
    await page.waitForLoadState('networkidle');

    const productRow = page
        .locator('tbody tr', {
            hasText: new RegExp(escapeRegExp(productName), 'i'),
        })
        .first();
    await expect(productRow).toBeVisible();
});
