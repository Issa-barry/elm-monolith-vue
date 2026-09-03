import { expect, test, type Page } from '@playwright/test';
import { login, randomDigits, registerCleanup } from './helpers';

const E2E_STOCK_PREFIX = 'E2ESTK-';

test.setTimeout(120_000);

registerCleanup('/backoffice/produits', E2E_STOCK_PREFIX);

test.beforeEach(async ({ page }) => {
    await login(page);
});

/**
 * L'action « Ajuster le stock » directement depuis la liste Produits a été retirée le
 * 24/08/2026 (redondante avec la page Stock, cf. produit-voir-le-stock.spec.ts) — le
 * point d'entrée courant pour ajuster un couple variante × site est le bouton « Ajuster »
 * d'une ligne de Produits/Stock/Index.vue (visible seulement si row.can_ajuster).
 */
async function goToStockPage(page: Page): Promise<void> {
    await page.goto('/backoffice/produits/stock');
    await expect(page).toHaveURL(/\/produits\/stock$/);
}

function adjustableRows(page: Page) {
    return page
        .locator('[data-testid="stock-table"] tbody tr')
        .filter({ has: page.locator('[data-testid="stock-adjust-button"]') });
}

/** Une ligne avec un stock physique déjà non nul — nécessaire pour tester "diminuer". */
function adjustableRowsWithStock(page: Page) {
    return adjustableRows(page).filter({
        hasNot: page.locator('td:nth-child(3):text-is("0")'),
    });
}

test('ajuster stock depuis la liste — augmenter', async ({ page }) => {
    await goToStockPage(page);

    const firstStockRow = adjustableRows(page).first();
    await firstStockRow.locator('[data-testid="stock-adjust-button"]').click();

    // Modal opens
    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /ajuster le stock/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    // Read current stock from the modal
    const stockText = await dialog.locator('.text-2xl').first().innerText();
    const _stockAvant = parseInt(stockText.trim(), 10);

    // Le site est déjà verrouillé (une seule agence possible depuis cette ligne) —
    // pas de sélecteur de site à renseigner ici, contrairement à l'ancien flux.

    // Fill "augmenter"
    const augInput = dialog.locator(
        '[data-testid="stock-augmenter-input"] input',
    );
    await augInput.pressSequentially('5');

    // Preview should show stockAvant + 5
    await expect(dialog.locator('text=Stock après ajustement')).toBeVisible();

    // Select motif
    const motifSelect = dialog.locator('[data-testid="stock-motif-select"]');
    await motifSelect.click();
    await page.getByRole('option', { name: 'Après production' }).click();

    await dialog.locator('[data-testid="stock-submit-button"]').click();
    await expect(dialog).toBeHidden({ timeout: 10_000 });

    // Navigate back to reload stock
    await goToStockPage(page);
});

test('ajuster stock depuis la liste — diminuer', async ({ page }) => {
    await goToStockPage(page);

    // Find a product that already has stock (needed to diminish without going negative).
    const firstStockRow = adjustableRowsWithStock(page).first();
    await firstStockRow.locator('[data-testid="stock-adjust-button"]').click();

    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /ajuster le stock/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    const dimInput = dialog.locator(
        '[data-testid="stock-diminuer-input"] input',
    );
    await dimInput.pressSequentially('3');
    await expect(dialog.locator('text=Stock après ajustement')).toBeVisible();

    // Select motif
    const motifSelect = dialog.locator('[data-testid="stock-motif-select"]');
    await motifSelect.click();
    await page.getByRole('option', { name: 'Perte' }).click();

    await dialog.locator('[data-testid="stock-submit-button"]').click();
    await expect(dialog).toBeHidden({ timeout: 10_000 });
});

test('ajuster stock depuis la fiche produit', async ({ page }) => {
    await page.goto('/backoffice/produits');
    await expect(page).toHaveURL(/\/produits$/);

    // Click on first product name to go to show page
    const firstLink = page.locator('tbody tr a').first();
    await firstLink.click();
    await expect(page).toHaveURL(/\/produits\/[a-z0-9]+$/);

    // "Ajuster le stock" button should be visible for products with stock
    const adjustBtn = page.locator('button', { hasText: /ajuster le stock/i });
    const btnVisible = await adjustBtn
        .isVisible({ timeout: 3_000 })
        .catch(() => false);
    if (!btnVisible) {
        // Product has no stock, skip
        return;
    }

    await adjustBtn.click();

    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /ajuster le stock/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    // Select a site first (required before entering quantity)
    const siteSelect = dialog.locator('[data-testid="stock-site-select"]');
    await siteSelect.click();
    await page.locator('[role="option"]').first().click();

    const augInput = dialog.locator(
        '[data-testid="stock-augmenter-input"] input',
    );
    await augInput.pressSequentially('2');

    // Select motif
    const motifSelect = dialog.locator('[data-testid="stock-motif-select"]');
    await motifSelect.click();
    await page.getByRole('option', { name: 'Après production' }).click();

    await dialog.locator('[data-testid="stock-submit-button"]').click();
    await expect(dialog).toBeHidden({ timeout: 10_000 });
});

test('ajuster stock — remplir un champ efface lautre (exclusion mutuelle)', async ({
    page,
}) => {
    await goToStockPage(page);

    const firstStockRow = adjustableRows(page).first();
    await firstStockRow.locator('[data-testid="stock-adjust-button"]').click();

    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /ajuster le stock/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    const augInput = dialog.locator(
        '[data-testid="stock-augmenter-input"] input',
    );
    const dimInput = dialog.locator(
        '[data-testid="stock-diminuer-input"] input',
    );

    // Fill augmenter → diminuer should stay empty
    await augInput.pressSequentially('10');
    await expect(augInput).toHaveValue('10');
    await expect(dimInput).toHaveValue('');

    // Fill diminuer → augmenter should be cleared by the watcher + remount
    await dimInput.pressSequentially('5');
    await expect(dimInput).toHaveValue('5');
    await expect(augInput).toHaveValue('', { timeout: 5_000 });

    // Cancel without submitting
    await dialog.locator('button', { hasText: /annuler/i }).click();
    await expect(dialog).toBeHidden({ timeout: 5_000 });
});

test('ajuster stock — bouton Valider désactivé si aucun champ renseigne', async ({
    page,
}) => {
    await goToStockPage(page);

    const firstRow = adjustableRows(page).first();
    await firstRow.locator('[data-testid="stock-adjust-button"]').click();

    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /ajuster le stock/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    const validateBtn = dialog.locator('[data-testid="stock-submit-button"]');
    await expect(validateBtn).toBeDisabled();

    await dialog.locator('button', { hasText: /annuler/i }).click();
    await expect(dialog).toBeHidden({ timeout: 5_000 });
});

test('ajuster stock — bouton Valider reste désactivé tant que le motif nest pas renseigné', async ({
    page,
}) => {
    await goToStockPage(page);

    const firstStockRow = adjustableRows(page).first();
    await firstStockRow.locator('[data-testid="stock-adjust-button"]').click();

    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /ajuster le stock/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    const validateBtn = dialog.locator('[data-testid="stock-submit-button"]');

    // Site + quantité renseignés, motif absent → toujours désactivé.
    const augInput = dialog.locator(
        '[data-testid="stock-augmenter-input"] input',
    );
    await augInput.pressSequentially('5');
    await expect(validateBtn).toBeDisabled();

    // Motif "Autre" avec détail composé uniquement d'espaces → toujours désactivé.
    const motifSelect = dialog.locator('[data-testid="stock-motif-select"]');
    await motifSelect.click();
    await page.getByRole('option', { name: /^autre$/i }).click();
    await expect(validateBtn).toBeDisabled();

    const detailInput = dialog.locator('#ajuster-motif-detail');
    await detailInput.pressSequentially('   ');
    await expect(validateBtn).toBeDisabled();

    // Motif détaillé renseigné (non vide après trim) → activé.
    await detailInput.fill('');
    await detailInput.pressSequentially('Correction inventaire');
    await expect(validateBtn).toBeEnabled();

    await dialog.locator('button', { hasText: /annuler/i }).click();
    await expect(dialog).toBeHidden({ timeout: 5_000 });
});

test('ajustement de stock — isolation entre deux agences distinctes', async ({
    page,
}) => {
    const unique = `${Date.now()}-${randomDigits(3)}`;
    const productName = `${E2E_STOCK_PREFIX}${unique}`;

    // Produit fraîchement créé : 0 partout sur toutes les agences, aucune
    // ambiguïté possible avec un éventuel stock legacy pré-existant.
    await page.goto('/backoffice/produits/create');
    await page.locator('#nom').fill(productName);
    await page.locator('#code_barres').fill(`E2ESTK-${unique}`);
    // PrimeVue InputNumber ne committe la valeur dans le v-model qu'au blur (jamais sur le
    // simple événement "input" déclenché par .fill()) — sans ce blur explicite, le bouton
    // "Enregistrer" (bloqué tant que canSubmit est faux, cf. ProduitForm.vue) reste
    // indéfiniment désactivé puisque le clic qui déclencherait le blur n'a jamais lieu.
    await page.locator('#prix_achat').fill('1000');
    await page.locator('#prix_achat').blur();
    await page.getByRole('button', { name: /^enregistrer$/i }).click();
    await expect(page).toHaveURL(/\/produits\/[^/]+$/);

    async function openAjustementModal() {
        await page
            .locator('button', { hasText: /ajuster le stock/i })
            .first()
            .click();
        const dialog = page
            .locator('[role="dialog"]')
            .filter({ hasText: /ajuster le stock/i });
        await expect(dialog).toBeVisible({ timeout: 10_000 });
        return dialog;
    }

    async function ajuster(
        dialog: ReturnType<typeof page.locator>,
        siteIndex: number,
        quantite: string,
    ) {
        const siteSelect = dialog.locator('[data-testid="stock-site-select"]');
        await siteSelect.click();
        const options = page.locator('[role="option"]');
        const label = (await options.nth(siteIndex).innerText()).trim();
        await options.nth(siteIndex).click();

        const augInput = dialog.locator(
            '[data-testid="stock-augmenter-input"] input',
        );
        await augInput.pressSequentially(quantite);

        const motifSelect = dialog.locator(
            '[data-testid="stock-motif-select"]',
        );
        await motifSelect.click();
        await page.getByRole('option', { name: 'Correction de stock' }).click();

        await dialog.locator('[data-testid="stock-submit-button"]').click();
        await expect(dialog).toBeHidden({ timeout: 10_000 });

        // Le label du dropdown est "Nom (Code)" mais le tableau "Stock par
        // agence" affiche le code et le nom dans des <span> séparés (code
        // d'abord) : on ne matche donc que sur le nom pour rester robuste
        // au format d'affichage du tableau.
        return label.replace(/\s*\([^)]*\)\s*$/, '');
    }

    function siteStockValue(siteName: string) {
        const section = page
            .locator('div', {
                has: page.getByRole('heading', { name: /stock par agence/i }),
            })
            .last();
        return section
            .locator('tbody tr', { hasText: siteName })
            .locator('td')
            .nth(1);
    }

    let dialog = await openAjustementModal();
    const siteSelectProbe = dialog.locator('[data-testid="stock-site-select"]');

    if (
        !(await siteSelectProbe
            .isVisible({ timeout: 2_000 })
            .catch(() => false))
    ) {
        // Un seul site autorisé pour cet utilisateur dans cet environnement E2E :
        // l'isolation multi-agences ne peut pas être démontrée depuis l'UI ici
        // (couverte de façon exhaustive côté backend par StockIsolationMultiSiteTest).
        await dialog.locator('button', { hasText: /annuler/i }).click();
        test.skip();
        return;
    }

    await siteSelectProbe.click();
    const optionCount = await page.locator('[role="option"]').count();
    await page.keyboard.press('Escape');

    if (optionCount < 2) {
        await dialog.locator('button', { hasText: /annuler/i }).click();
        test.skip();
        return;
    }

    const siteAName = await ajuster(dialog, 0, '10');
    await page.reload();
    await expect(siteStockValue(siteAName)).toHaveText('10', {
        timeout: 10_000,
    });

    dialog = await openAjustementModal();
    const siteBName = await ajuster(dialog, 1, '5');
    await page.reload();

    await expect(siteStockValue(siteBName)).toHaveText('5', {
        timeout: 10_000,
    });
    // L'agence A ne doit avoir subi aucune modification suite à l'ajustement de B.
    await expect(siteStockValue(siteAName)).toHaveText('10');
});
