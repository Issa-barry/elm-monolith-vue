import { expect, test } from '@playwright/test';
import { ensureModuleEnabled, login } from './helpers';

test.setTimeout(180_000);

test.beforeEach(async ({ page }) => {
    await login(page);
    await ensureModuleEnabled(page, 'module.ventes');
    await ensureModuleEnabled(page, 'module.cashback');
});

test('cashback index renders and exposes its filters drawer', async ({
    page,
}) => {
    await page.goto('/backoffice/cashback');
    await expect(page).toHaveURL(/\/cashback/, { timeout: 20_000 });
    await expect(
        page.getByRole('heading', { name: /cashback/i }).first(),
    ).toBeVisible({
        timeout: 20_000,
    });

    // Pas de recherche globale (standard DataFilters) : Statut, Client et
    // Période vivent dans le drawer Filtres.
    await page.getByRole('button', { name: /^filtres/i }).click();
    const drawer = page.getByTestId('filters-drawer');
    await expect(drawer).toBeVisible();
    await expect(drawer.getByText('Statut', { exact: true })).toBeVisible();
    await expect(drawer.getByText('Client', { exact: true })).toBeVisible();
});

test('cashback row actions menu is available when transactions exist', async ({
    page,
}) => {
    await page.goto('/backoffice/cashback');
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
