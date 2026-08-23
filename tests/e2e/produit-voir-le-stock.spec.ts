import { expect, test } from '@playwright/test';
import { escapeRegExp, getVisibleSearchInput, login, randomDigits, registerCleanup } from './helpers';

const PREFIX = 'E2EVOIRSTOCK';

test.setTimeout(120_000);

registerCleanup('/backoffice/produits', PREFIX);

test.beforeEach(async ({ page }) => {
    await login(page);
});

/**
 * « Ajuster le stock » (modale directe) a été retiré du menu de la liste Produits — redondant
 * avec la page Stock, où site et variante sont clairement identifiés (décision produit du
 * 24/08/2026). Remplacé par « Voir le stock », qui ouvre Produits/Stock/Index.vue filtré sur
 * ce produit (réutilise le champ de recherche existant de cette page, cf. StockController::
 * stockQuery()). L'action « Ajuster le stock (par variante) », qui renvoie vers la fiche
 * détail du produit, reste inchangée pour les produits à déclinaisons.
 */
test('le menu Produits propose « Voir le stock » et plus « Ajuster le stock »', async ({
    page,
}) => {
    const unique = `${Date.now()}-${randomDigits(3)}`;
    const productName = `${PREFIX} ${unique}`;

    // Produit simple (une seule variante) avec du stock, pour faire apparaître l'action dans
    // le menu (v-if="data.has_stock").
    await page.goto('/backoffice/produits/create');
    await page.locator('#nom').fill(productName);
    await page.locator('#prix_achat').fill('1000');
    await page.locator('#prix_achat').blur();
    await page.getByRole('button', { name: /^enregistrer$/i }).click();
    await expect(page).toHaveURL(/\/produits\/[^/]+$/, { timeout: 20_000 });

    const adjustBtn = page.locator('button', { hasText: /ajuster le stock/i });
    if (await adjustBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await adjustBtn.click();
        const dialog = page
            .locator('[role="dialog"]')
            .filter({ hasText: /ajuster le stock/i });
        await expect(dialog).toBeVisible({ timeout: 10_000 });
        const siteSelect = dialog.locator('[data-testid="stock-site-select"]');
        await siteSelect.click();
        await page.locator('[role="option"]').first().click();
        const augInput = dialog.locator(
            '[data-testid="stock-augmenter-input"] input',
        );
        await augInput.pressSequentially('5');
        const motifSelect = dialog.locator('[data-testid="stock-motif-select"]');
        await motifSelect.click();
        await page.getByRole('option', { name: 'Après production' }).click();
        await dialog.locator('[data-testid="stock-submit-button"]').click();
        await expect(dialog).toBeHidden({ timeout: 10_000 });
    }

    await page.goto('/backoffice/produits');
    await expect(page).toHaveURL(/\/produits$/);
    const searchInput = getVisibleSearchInput(page);
    await searchInput.fill(productName);
    await searchInput.press('Enter');
    await page.waitForLoadState('networkidle');

    const productRow = page
        .locator('tbody tr', {
            hasText: new RegExp(escapeRegExp(productName), 'i'),
        })
        .first();
    await expect(productRow).toBeVisible();
    await productRow.locator('button').last().click();

    await expect(
        page.getByRole('menuitem', { name: /^voir le stock$/i }),
    ).toBeVisible({ timeout: 5_000 });
    await expect(
        page.getByRole('menuitem', { name: /^ajuster le stock$/i }),
    ).toHaveCount(0);
});

test('« Voir le stock » ouvre la page Stock filtrée sur ce produit', async ({
    page,
}) => {
    const unique = `${Date.now()}-${randomDigits(3)}`;
    const productName = `${PREFIX} ${unique}`;

    await page.goto('/backoffice/produits/create');
    await page.locator('#nom').fill(productName);
    await page.locator('#prix_achat').fill('1000');
    await page.locator('#prix_achat').blur();
    await page.getByRole('button', { name: /^enregistrer$/i }).click();
    await expect(page).toHaveURL(/\/produits\/[^/]+$/, { timeout: 20_000 });

    await page.goto('/backoffice/produits');
    await expect(page).toHaveURL(/\/produits$/);
    const searchInput = getVisibleSearchInput(page);
    await searchInput.fill(productName);
    await searchInput.press('Enter');
    await page.waitForLoadState('networkidle');

    const productRow = page
        .locator('tbody tr', {
            hasText: new RegExp(escapeRegExp(productName), 'i'),
        })
        .first();
    await expect(productRow).toBeVisible();
    await productRow.locator('button').last().click();

    const voirStockItem = page.getByRole('menuitem', {
        name: /^voir le stock$/i,
    });
    await expect(voirStockItem).toBeVisible({ timeout: 5_000 });
    await voirStockItem.click();

    await expect(page).toHaveURL(/\/produits\/stock\?search=/, {
        timeout: 15_000,
    });

    // La page Stock est bien pré-filtrée sur CE produit — seules ses lignes apparaissent
    // (réutilise le champ de recherche existant de Produits/Stock/Index.vue).
    await expect(page.locator('body')).toContainText(productName, {
        timeout: 10_000,
    });
});
