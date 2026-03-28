import { expect, test, type Page } from '@playwright/test';

const E2E_EMAIL = process.env.E2E_EMAIL ?? 'superadmin@admin.com';
const E2E_PHONE = process.env.E2E_PHONE ?? '+33758855039';
const E2E_PASSWORD = process.env.E2E_PASSWORD ?? 'Staff@2025';
const E2E_PRODUCT_PREFIX = 'E2E Produit';

test.setTimeout(90_000);

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function getProductSearchInput(page: Page) {
    return page.locator('input[placeholder*="rechercher un produit" i]:visible').first();
}

async function login(page: Parameters<typeof test>[0]['page']) {
    await page.goto('/login');

    const emailInput = page.locator('input[name="email"]');
    if ((await emailInput.count()) > 0) {
        await emailInput.fill(E2E_EMAIL);
    } else {
        // New auth form submits a hidden "telephone" field.
        await expect(page.locator('input[name="telephone"]')).toHaveCount(1);
        await page.evaluate((phone) => {
            const hiddenPhone = document.querySelector('input[name="telephone"]') as HTMLInputElement | null;
            if (hiddenPhone) hiddenPhone.value = phone;
        }, E2E_PHONE);
    }

    await page.locator('input[name="password"]').fill(E2E_PASSWORD);
    await page.getByRole('button', { name: /se connecter/i }).click();

    await page.waitForURL((url) => !url.pathname.endsWith('/login'));
    await expect(page.getByRole('link', { name: /produits/i })).toBeVisible();
}

async function cleanupE2EProducts(page: Page) {
    await login(page);
    await page.goto('/produits');

    const searchInput = getProductSearchInput(page);
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

        try {
            const actionButton = firstRow.locator('td').last().locator('button').first();
            await actionButton.click({ timeout: 2000 });

            const deleteItem = page.getByRole('menuitem', { name: /supprimer/i }).first();
            if (!(await deleteItem.isVisible().catch(() => false))) {
                break;
            }
            await deleteItem.click({ timeout: 3000, force: true });

            const confirmDelete = page.getByRole('button', { name: /^supprimer$/i }).last();
            if (!(await confirmDelete.isVisible().catch(() => false))) {
                break;
            }
            await confirmDelete.click({ timeout: 2000 });
        } catch {
            break;
        }

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

    const searchInput = getProductSearchInput(page);
    await searchInput.fill(productName);

    const productRow = page.locator('tbody tr', {
        hasText: new RegExp(escapeRegExp(productName), 'i'),
    }).first();
    await expect(productRow).toBeVisible();
});
