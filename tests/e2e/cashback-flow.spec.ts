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
    await expect(
        page.getByRole('heading', { name: /cashback/i }).first(),
    ).toBeVisible({
        timeout: 20_000,
    });

    const search = page
        .locator('input[placeholder*="client" i]:visible')
        .first();
    await expect(search).toBeVisible({ timeout: 10_000 });
    await search.fill('zzzz-no-result-e2e');
    await expect(search).toHaveValue('zzzz-no-result-e2e');
});

test('cashback row actions menu is available when transactions exist', async ({
    page,
}) => {
    await page.goto('/cashback');
    await expect(page).toHaveURL(/\/cashback/, { timeout: 20_000 });

    const actionButtons = page.locator(
        '.p-datatable-table tbody tr button:has(svg.lucide-more-vertical):visible',
    );
    const actionCount = await actionButtons.count();

    if (actionCount === 0) {
        return;
    }

    const firstActionButton = actionButtons.first();
    await expect(firstActionButton).toBeVisible({ timeout: 15_000 });
    await firstActionButton.click();

    await expect(
        page
            .getByRole('menuitem')
            .filter({ hasText: /historique|valider|verser/i })
            .first(),
    ).toBeVisible({
        timeout: 10_000,
    });
});
