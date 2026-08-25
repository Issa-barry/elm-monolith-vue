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
    await expect(page.locator('[data-testid="stock-table"]')).toBeVisible();

    // Filtres en mode trigger-only (AGENTS.md §2) : un seul bouton « Filtres » ouvre le
    // drawer — plus de grosse barre de champs visible directement sur la page.
    await page.getByRole('button', { name: /^filtres/i }).click();
    const drawer = page.locator('[data-testid="filters-drawer"]');
    await expect(drawer).toBeVisible();
    await expect(drawer.locator('[data-testid="agency-filter"]')).toBeVisible();
    await expect(
        drawer.locator('[data-testid="filter-field-stock_statut"]'),
    ).toBeVisible();
    await expect(
        drawer.locator('[data-testid="filter-field-search"]'),
    ).toBeVisible();

    const firstRow = page
        .locator('[data-testid="stock-table"] tbody tr')
        .first();
    await expect(firstRow).toBeVisible();
    const sku = (
        await firstRow.locator('[data-testid="stock-row-sku"]').innerText()
    )
        .replace(/^SKU\s*/i, '')
        .trim();

    await drawer
        .locator('[data-testid="filter-field-search"] input')
        .fill(sku);
    await drawer.locator('[data-testid="filters-apply"]').click();
    await expect(drawer).toBeHidden();
    await expect(page).toHaveURL(/search=/);
    await expect(
        page
            .locator('[data-testid="stock-table"] tbody tr')
            .first()
            .locator('[data-testid="stock-row-sku"]'),
    ).toContainText(sku);

    const filteredRow = page
        .locator('[data-testid="stock-table"] tbody tr')
        .first();
    await filteredRow.locator('[data-testid="stock-history-button"]').click();
    // Le titre de la modale est « Produit · Variante · Agence » (jamais le mot "historique" lui-
    // même) — on cible plutôt son onglet "Ajustements stock", toujours présent.
    const historyDialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /ajustements stock/i });
    await expect(historyDialog).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(historyDialog).toBeHidden();

    // Colonne « Physique » (index 2 : Produit, Agence, Physique, Engagé, Bloqué, Disponible…) —
    // c'est le stock PHYSIQUE que la modale « Ajuster » modifie, jamais le disponible.
    const physiqueCell = filteredRow.locator('td').nth(2);
    const physiqueAvant = Number(
        (await physiqueCell.innerText()).replace(/[^0-9-]/g, ''),
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
    await expect(physiqueCell).toContainText(
        new Intl.NumberFormat('fr-FR').format(physiqueAvant + 1),
    );

    // Mobile : cartes empilées, jamais le tableau ni de débordement horizontal.
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.locator('[data-testid="stock-table"]')).toBeHidden();
    await expect(
        page.locator('[data-testid="stock-card"]').first(),
    ).toBeVisible();
    const bodyScrollWidth = await page.evaluate(
        () => document.body.scrollWidth,
    );
    const viewportWidth = await page.evaluate(() => window.innerWidth);
    expect(bodyScrollWidth).toBeLessThanOrEqual(viewportWidth + 1);
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
