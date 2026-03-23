import { expect, test, type Page } from '@playwright/test';

const E2E_EMAIL = process.env.E2E_EMAIL ?? 'superadmin@admin.com';
const E2E_PASSWORD = process.env.E2E_PASSWORD ?? 'password';
const E2E_PRODUCT_PREFIX = 'E2E Produit';

test.setTimeout(90_000);

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

async function login(page: Parameters<typeof test>[0]['page']) {
    await page.goto('/login');

    await page.locator('input[name="email"]').fill(E2E_EMAIL);
    await page.locator('input[name="password"]').fill(E2E_PASSWORD);
    await page.getByRole('button', { name: /se connecter/i }).click();

    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.getByRole('link', { name: /produits/i })).toBeVisible();
}

async function cleanupE2EProducts(page: Page) {
    await login(page);
    await page.goto('/produits');

    const searchInput = page.getByPlaceholder(/rechercher un produit/i);
    await searchInput.fill(E2E_PRODUCT_PREFIX);

    for (let i = 0; i < 10; i++) {
        const emptyState = page.getByText(/aucun produit trouv/i);
        if (await emptyState.isVisible().catch(() => false)) {
            break;
        }

        const firstRow = page.locator('tbody tr').first();
        if (!(await firstRow.isVisible().catch(() => false))) {
            break;
        }

        const rowText = (await firstRow.innerText()).toLowerCase();

        // Safety guard: cleanup only test data rows.
        if (!rowText.includes('e2e produit')) {
            break;
        }

        const actionButton = firstRow.locator('td').last().locator('button').first();
        await actionButton.click({ timeout: 2000 });

        const deleteItem = page.getByRole('menuitem', { name: /supprimer/i }).first();
        if (!(await deleteItem.isVisible().catch(() => false))) {
            break;
        }
        await deleteItem.click({ timeout: 2000 });

        const confirmDelete = page.getByRole('button', { name: /^supprimer$/i }).last();
        if (!(await confirmDelete.isVisible().catch(() => false))) {
            break;
        }
        await confirmDelete.click({ timeout: 2000 });

        await page.waitForLoadState('networkidle');
        await searchInput.fill(E2E_PRODUCT_PREFIX);
    }
}

test.afterEach(async ({ browser }) => {
    const context = await browser.newContext();

    try {
        const cleanupPage = await context.newPage();
        await cleanupE2EProducts(cleanupPage);
    } catch (error) {
        console.warn('E2E cleanup warning:', error);
    } finally {
        await context.close().catch(() => undefined);
    }
});

test('login + create product + verify list', async ({ page }) => {
    const unique = `${Date.now()}-${Math.floor(Math.random() * 1000)}`;
    const productName = `${E2E_PRODUCT_PREFIX} ${unique}`;
    const supplierCode = `E2E-${unique}`;

    await login(page);

    await page.goto('/produits/create');
    await expect(page.getByRole('heading', { name: /nouveau produit/i })).toBeVisible();

    await page.locator('#nom').fill(productName);
    await page.locator('#code_fournisseur').fill(supplierCode);
    await page.getByRole('button', { name: /^enregistrer$/i }).click();

    await expect(page).toHaveURL(/\/produits$/);

    const searchInput = page.getByPlaceholder(/rechercher un produit/i);
    await searchInput.fill(productName);

    await expect(page.getByText(new RegExp(escapeRegExp(productName), 'i'))).toBeVisible();
});