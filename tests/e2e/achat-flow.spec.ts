import { expect, test } from '@playwright/test';
import {
    ensureModuleEnabled,
    escapeRegExp,
    getVisibleSearchInput,
    login,
    openRowActions,
    randomDigits,
} from './helpers';

test.setTimeout(240_000);

test.beforeEach(async ({ page }) => {
    await login(page);
    await ensureModuleEnabled(page, 'module.achats');
});

test('create achat -> annuler -> supprimer depuis la liste', async ({
    page,
}) => {
    const note = `E2E-ACHAT-${Date.now()}${randomDigits(2)}`.slice(-18);

    await page.goto('/backoffice/achats/create');
    await expect(page).toHaveURL(/\/achats\/create$/, { timeout: 20_000 });

    await page
        .locator('input[placeholder*="fournisseur" i]')
        .first()
        .fill(note);

    const submit = page
        .locator('#achat-form button[type="submit"]:visible')
        .first();
    await expect(submit).toBeEnabled({ timeout: 15_000 });
    await submit.click();

    await expect(page).toHaveURL(/\/achats\/[a-z0-9]+$/, { timeout: 30_000 });

    const annulerBtn = page
        .getByRole('button', { name: /annuler(?: la commande)?/i })
        .first();
    await expect(annulerBtn).toBeVisible({ timeout: 15_000 });
    await annulerBtn.click();

    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /annuler la commande/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });
    await dialog
        .locator('textarea[placeholder*="raison" i]')
        .fill('Annulation e2e');
    await dialog
        .getByRole('button', { name: /confirmer l'annulation/i })
        .click();

    await expect(page.getByText(/motif d['’]annulation/i)).toBeVisible({
        timeout: 20_000,
    });

    await page.goto('/backoffice/achats');
    await expect(page).toHaveURL(/\/achats$/, { timeout: 15_000 });

    const search = getVisibleSearchInput(page);
    await search.fill(note);
    await search.press('Enter');
    await page.waitForLoadState('networkidle');

    const row = page
        .locator('.p-datatable-table tbody tr', {
            hasText: new RegExp(escapeRegExp(note), 'i'),
        })
        .first();
    await expect(row).toBeVisible({ timeout: 15_000 });

    await openRowActions(row);
    await page
        .getByRole('menuitem', { name: /supprimer/i })
        .first()
        .click();
    await page
        .getByRole('button', { name: /supprimer/i })
        .last()
        .click();

    await page.waitForLoadState('networkidle');
    await search.fill(note);
    await search.press('Enter');
    await page.waitForLoadState('networkidle');
    await expect(
        page.locator('.p-datatable-table tbody tr', {
            hasText: new RegExp(escapeRegExp(note), 'i'),
        }),
    ).toHaveCount(0);
});
