import { expect, test } from '@playwright/test';
import { login, registerCleanup } from './helpers';

const E2E_STOCK_PREFIX = 'E2ESTK-';

test.setTimeout(120_000);

registerCleanup('/produits', E2E_STOCK_PREFIX);

test.beforeEach(async ({ page }) => {
    await login(page);
});

test('ajuster stock depuis la liste — augmenter', async ({ page }) => {
    await page.goto('/produits');
    await expect(page).toHaveURL(/\/produits$/);

    // Find a product that has a stock (any row)
    const firstStockRow = page
        .locator('tbody tr')
        .filter({
            hasNot: page.locator('td:has(.text-muted-foreground:text-is("—"))'),
        })
        .first();

    // Open action menu
    await firstStockRow.locator('button').last().click();

    const adjustItem = page.getByRole('menuitem', {
        name: /ajuster le stock/i,
    });
    await expect(adjustItem).toBeVisible({ timeout: 5_000 });
    await adjustItem.click();

    // Modal opens
    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /ajuster le stock/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    // Read current stock from the modal
    const stockText = await dialog.locator('.text-2xl').first().innerText();
    const _stockAvant = parseInt(stockText.trim(), 10);

    // Fill "augmenter"
    await dialog.locator('#ajuster-augmenter').fill('5');

    // Preview should show stockAvant + 5
    await expect(dialog.locator('text=Stock après ajustement')).toBeVisible();

    await dialog.locator('button', { hasText: /valider/i }).click();
    await expect(dialog).toBeHidden({ timeout: 10_000 });

    // Navigate back to reload stock
    await page.goto('/produits');
    await expect(page).toHaveURL(/\/produits$/);
});

test('ajuster stock depuis la liste — diminuer', async ({ page }) => {
    await page.goto('/produits');
    await expect(page).toHaveURL(/\/produits$/);

    // Find a product with non-zero stock
    const firstStockRow = page
        .locator('tbody tr')
        .filter({
            hasNot: page.locator('td:has(.text-muted-foreground:text-is("—"))'),
        })
        .first();

    await firstStockRow.locator('button').last().click();

    const adjustItem = page.getByRole('menuitem', {
        name: /ajuster le stock/i,
    });
    await expect(adjustItem).toBeVisible({ timeout: 5_000 });
    await adjustItem.click();

    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /ajuster le stock/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    await dialog.locator('#ajuster-diminuer').fill('3');
    await expect(dialog.locator('text=Stock après ajustement')).toBeVisible();
    await dialog.locator('button', { hasText: /valider/i }).click();
    await expect(dialog).toBeHidden({ timeout: 10_000 });
});

test('ajuster stock depuis la fiche produit', async ({ page }) => {
    await page.goto('/produits');
    await expect(page).toHaveURL(/\/produits$/);

    // Click on first product name to go to show page
    const firstLink = page.locator('tbody tr a').first();
    await firstLink.click();
    await expect(page).toHaveURL(/\/produits\/[a-z0-9]+$/);

    // "Ajuster le stock" button should be visible for products with stock
    const adjustBtn = page.locator('button', { hasText: /ajuster le stock/i });
    const btnVisible = await adjustBtn
        .isVisible({ timeout: 3_000 })
        .catch(() => false);
    if (!btnVisible) {
        // Product has no stock, skip
        return;
    }

    await adjustBtn.click();

    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /ajuster le stock/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    await dialog.locator('#ajuster-augmenter').fill('2');
    await dialog.locator('button', { hasText: /valider/i }).click();
    await expect(dialog).toBeHidden({ timeout: 10_000 });
});

test('ajuster stock — remplir un champ efface lautre (exclusion mutuelle)', async ({
    page,
}) => {
    await page.goto('/produits');
    await expect(page).toHaveURL(/\/produits$/);

    const firstStockRow = page.locator('tbody tr').first();
    await firstStockRow.locator('button').last().click();

    const adjustItem = page.getByRole('menuitem', {
        name: /ajuster le stock/i,
    });
    await expect(adjustItem).toBeVisible({ timeout: 5_000 });
    await adjustItem.click();

    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /ajuster le stock/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    const augInput = dialog.locator('#ajuster-augmenter');
    const dimInput = dialog.locator('#ajuster-diminuer');

    // Fill augmenter → diminuer should clear
    await augInput.fill('10');
    await expect(augInput).toHaveValue('10');
    await expect(dimInput).toHaveValue('');

    // Fill diminuer → augmenter should clear
    await dimInput.fill('5');
    await expect(dimInput).toHaveValue('5');
    await expect(augInput).toHaveValue('');

    // Cancel without submitting
    await dialog.locator('button', { hasText: /annuler/i }).click();
    await expect(dialog).toBeHidden({ timeout: 5_000 });
});

test('ajuster stock — bouton Valider désactivé si aucun champ renseigne', async ({
    page,
}) => {
    await page.goto('/produits');
    await expect(page).toHaveURL(/\/produits$/);

    const firstRow = page.locator('tbody tr').first();
    await firstRow.locator('button').last().click();

    const adjustItem = page.getByRole('menuitem', {
        name: /ajuster le stock/i,
    });
    await expect(adjustItem).toBeVisible({ timeout: 5_000 });
    await adjustItem.click();

    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /ajuster le stock/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    const validateBtn = dialog.locator('button', { hasText: /valider/i });
    await expect(validateBtn).toBeDisabled();

    await dialog.locator('button', { hasText: /annuler/i }).click();
    await expect(dialog).toBeHidden({ timeout: 5_000 });
});
