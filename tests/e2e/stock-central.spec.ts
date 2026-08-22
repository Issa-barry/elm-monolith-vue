import { expect, test, type Page } from '@playwright/test';
import { E2E_PASSWORD, fillLoginIdentifier, login } from './helpers';

test.setTimeout(120_000);

async function loginAsManager(page: Page): Promise<void> {
    await page.context().clearCookies();
    await page.goto('/login');
    await page.waitForSelector('input[name="password"]', {
        timeout: 20_000,
    });
    await fillLoginIdentifier(page, { phone: '+224622176056' });
    await page.locator('input[name="password"]').fill(E2E_PASSWORD);
    await page.getByRole('button', { name: /se connecter/i }).click();
    await expect(page).not.toHaveURL(/\/login(?:\?.*)?$/, {
        timeout: 20_000,
    });
}

test('Produits vers Stock, filtres, historique et ajustement', async ({
    page,
}) => {
    await login(page);
    await page.goto('/backoffice/produits');

    const stockLink = page.locator(
        'a[href="/backoffice/produits/stock"]:visible',
    );
    await expect(stockLink).toBeVisible();
    await stockLink.click();
    await expect(page).toHaveURL(/\/backoffice\/produits\/stock/);

    await expect(page.getByRole('heading', { name: /^stock$/i })).toBeVisible();
    await expect(page.locator('[data-testid="agency-filter"]')).toBeVisible();
    await expect(
        page.locator('[data-testid="filter-inline-stock_statut"]'),
    ).toBeVisible();
    await expect(
        page.locator('[data-testid="filter-inline-search"]'),
    ).toBeVisible();
    await expect(page.locator('[data-testid="stock-table"]')).toBeVisible();

    const firstRow = page
        .locator('[data-testid="stock-table"] tbody tr')
        .first();
    await expect(firstRow).toBeVisible();
    const sku = (await firstRow.locator('td').nth(2).innerText()).trim();
    await page.locator('[data-testid="filter-inline-search"]').fill(sku);
    await page.locator('[data-testid="filters-search"]').click();
    await expect(page).toHaveURL(/search=/);
    await expect(
        page
            .locator('[data-testid="stock-table"] tbody tr')
            .first()
            .locator('td')
            .nth(2),
    ).toHaveText(sku);

    const filteredRow = page
        .locator('[data-testid="stock-table"] tbody tr')
        .first();
    await filteredRow.locator('[data-testid="stock-history-button"]').click();
    const historyDialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /historique/i });
    await expect(historyDialog).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(historyDialog).toBeHidden();

    const quantityCell = filteredRow.locator('td').nth(5);
    const quantityBefore = Number(
        (await quantityCell.innerText()).replace(/[^0-9]/g, ''),
    );
    await filteredRow.locator('[data-testid="stock-adjust-button"]').click();
    const adjustDialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /ajuster le stock/i });
    await expect(adjustDialog).toBeVisible();
    await adjustDialog
        .locator('[data-testid="stock-augmenter-input"] input')
        .fill('1');
    await adjustDialog.locator('[data-testid="stock-motif-select"]').click();
    await page.getByRole('option', { name: 'Correction de stock' }).click();
    await adjustDialog.locator('[data-testid="stock-submit-button"]').click();
    await expect(adjustDialog).toBeHidden();
    await expect(quantityCell).toContainText(
        new Intl.NumberFormat('fr-FR').format(quantityBefore + 1),
    );

    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.locator('[data-testid="stock-table-scroll"]')).toHaveCSS(
        'overflow-x',
        'auto',
    );
});

test('un manager sans droit fin ne voit aucune action Ajuster', async ({
    page,
}) => {
    await loginAsManager(page);
    await page.goto('/backoffice/produits/stock');
    await expect(page.getByRole('heading', { name: /^stock$/i })).toBeVisible();
    await expect(
        page.locator('[data-testid="stock-adjust-button"]'),
    ).toHaveCount(0);
    await expect(
        page.locator('[data-testid="stock-history-button"]').first(),
    ).toBeVisible();
});
