import { expect, test } from '@playwright/test';
import { ensureModuleEnabled, login } from './helpers';

test.setTimeout(180_000);

test.beforeEach(async ({ page }) => {
    await login(page);
    await ensureModuleEnabled(page, 'module.ventes');
    await ensureModuleEnabled(page, 'module.cashback');
});

test('cashback index renders and supports search/filter controls', async ({
    page,
}) => {
    await page.goto('/cashback');
    await expect(page).toHaveURL(/\/cashback/, { timeout: 20_000 });
    await expect(page.locator('body')).toContainText(/cashback/i, {
        timeout: 20_000,
    });

    const search = page
        .locator(
            'input[placeholder*="client" i][placeholder*="téléphone" i], input[placeholder*="client" i][placeholder*="telephone" i]',
        )
        .first();
    await expect(search).toBeVisible({ timeout: 10_000 });
    await search.fill('zzzz-no-result-e2e');

    await expect(page.locator('body')).toContainText(/aucun cashback/i, {
        timeout: 20_000,
    });
});

test('cashback row actions menu is available when transactions exist', async ({
    page,
}) => {
    await page.goto('/cashback');
    await expect(page).toHaveURL(/\/cashback/, { timeout: 20_000 });

    const firstRow = page.locator('tbody tr').first();
    const rowCount = await page.locator('tbody tr').count();

    if (rowCount === 0) {
        await expect(page.locator('body')).toContainText(/aucun cashback/i);
        return;
    }

    await expect(firstRow).toBeVisible({ timeout: 15_000 });
    await firstRow.locator('button').last().click();

    await expect(
        page
            .getByRole('menuitem')
            .filter({ hasText: /historique|valider|verser/i })
            .first(),
    ).toBeVisible({
        timeout: 10_000,
    });
});
