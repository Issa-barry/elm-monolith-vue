import { expect, test } from '@playwright/test';
import {
    ensureModuleEnabled,
    login,
    selectOptionFromCombobox,
} from './helpers';

test.setTimeout(240_000);

test.beforeEach(async ({ page }) => {
    await login(page);
    await ensureModuleEnabled(page, 'module.logistique');
});

test('logistique pages render (transferts + receptions + create)', async ({
    page,
}) => {
    await page.goto('/logistique/transferts');
    await expect(page).toHaveURL(/\/logistique\/transferts$/, {
        timeout: 20_000,
    });
    await expect(page.locator('body')).toContainText(/transferts/i, {
        timeout: 20_000,
    });

    await page.goto('/logistique/receptions');
    await expect(page).toHaveURL(/\/logistique\/receptions$/, {
        timeout: 20_000,
    });
    await expect(page.locator('body')).toContainText(/réceptions|receptions/i, {
        timeout: 20_000,
    });

    await page.goto('/logistique/creer');
    await expect(page).toHaveURL(/\/logistique\/creer$/, { timeout: 20_000 });
    await expect(page.locator('#logistique-form')).toBeVisible({
        timeout: 15_000,
    });
});

test('create transfert -> annuler depuis la page détail', async ({ page }) => {
    await page.goto('/logistique/creer');
    await expect(page.locator('#logistique-form')).toBeVisible({
        timeout: 20_000,
    });

    const formComboboxes = page
        .locator('#logistique-form')
        .getByRole('combobox');

    await selectOptionFromCombobox(
        page,
        formComboboxes.nth(0),
        /lansanaya|lambagny|dabompa/i,
    );
    await selectOptionFromCombobox(page, formComboboxes.nth(1));

    const submit = page
        .locator('#logistique-form button[type="submit"]:visible')
        .first();
    await expect(submit).toBeEnabled({ timeout: 15_000 });
    await submit.click();

    await expect(page).toHaveURL(/\/logistique\/[a-z0-9]+$/, {
        timeout: 30_000,
    });

    const annuler = page.getByRole('button', { name: /^annuler$/i }).first();
    await expect(annuler).toBeVisible({ timeout: 15_000 });
    await annuler.click();

    await expect(page.locator('body')).toContainText(/annulé|annule/i, {
        timeout: 20_000,
    });
});
