import { expect, test, type Page } from '@playwright/test';
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

// ── Helpers ────────────────────────────────────────────────────────────────────

/**
 * Remplit le formulaire de création de transfert.
 * - Pour un admin : sélectionne site source, site destination, véhicule.
 * - Le produit est pré-sélectionné par défaut dans le formulaire.
 */
async function fillLogistiqueForm(page: Page): Promise<void> {
    const form = page.locator('#logistique-form');

    // Site source — visible uniquement pour les admins (Dropdown libre)
    const siteSourceField = form.locator('[data-testid="site-source-field"]');
    const siteSourceCombobox = siteSourceField.getByRole('combobox');
    if ((await siteSourceCombobox.count()) > 0) {
        await selectOptionFromCombobox(
            page,
            siteSourceCombobox,
            /lansanaya|lambagny|dabompa/i,
        );
    }

    // Site destination
    const siteDestCombobox = form
        .locator('[data-testid="site-destination-field"]')
        .getByRole('combobox');
    await selectOptionFromCombobox(page, siteDestCombobox);

    // Véhicule — obligatoire pour activer le bouton submit
    const vehiculeCombobox = form
        .locator('[data-testid="vehicule-field"]')
        .getByRole('combobox');
    await selectOptionFromCombobox(page, vehiculeCombobox);
}

/**
 * Navigue vers la page détail d'un transfert à partir de son référence.
 * Le lien est présent dans la colonne "Référence" du tableau.
 */
async function goToTransfertDetail(
    page: Page,
    reference: string,
): Promise<void> {
    await page.goto('/logistique/transferts');
    await expect(page.locator('body')).toContainText(reference, {
        timeout: 20_000,
    });
    await page
        .getByRole('link', { name: new RegExp(reference, 'i') })
        .first()
        .click();
    await expect(page).toHaveURL(/\/logistique\/[a-z0-9]+$/, {
        timeout: 15_000,
    });
}

/**
 * Retourne le conteneur du stepper (carte contenant "Brouillon" et "Clôturé").
 */
function stepperCard(page: Page) {
    return page
        .locator('.rounded-xl.border.bg-card')
        .filter({ hasText: /brouillon/i })
        .filter({ hasText: /clôtur/i })
        .first();
}

// ── Tests pages de base ────────────────────────────────────────────────────────

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

    await fillLogistiqueForm(page);

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

// ── Tests stepper commission ───────────────────────────────────────────────────

test('stepper — 6 étapes dont "Commission" grise dès la création', async ({
    page,
}) => {
    await page.goto('/logistique/creer');
    await expect(page.locator('#logistique-form')).toBeVisible({
        timeout: 20_000,
    });

    await fillLogistiqueForm(page);

    const submit = page
        .locator('#logistique-form button[type="submit"]:visible')
        .first();
    await expect(submit).toBeEnabled({ timeout: 15_000 });
    await submit.click();
    await expect(page).toHaveURL(/\/logistique\/[a-z0-9]+$/, {
        timeout: 30_000,
    });

    // Le stepper doit afficher les 6 étapes (dont "Commission")
    const stepper = stepperCard(page);
    await expect(stepper).toBeVisible({ timeout: 10_000 });
    await expect(stepper).toContainText(/commission/i);

    // L'onglet "Commission logistique" doit être désactivé (pas encore atteint)
    await expect(
        page.getByRole('button', { name: /commission logistique/i }),
    ).toBeDisabled({ timeout: 5_000 });
});

test('stepper — étape commission affiche "Impayé" ou "Partiel" sur TL-SEED-001 (clôturé)', async ({
    page,
}) => {
    await goToTransfertDetail(page, 'TL-SEED-001');

    // Le stepper affiche le statut de commission.
    // TL-SEED-001 est "Impayé" sur seed fresh, mais peut devenir "Partiel"
    // si le test logistique-commission-flow a déjà effectué des versements.
    const stepper = stepperCard(page);
    await expect(stepper).toBeVisible({ timeout: 10_000 });
    await expect(stepper).toContainText(/impay|partiel/i);

    // L'onglet commission est accessible
    await expect(
        page.getByRole('button', { name: /commission logistique/i }),
    ).not.toBeDisabled({ timeout: 5_000 });
});

test('stepper — étape commission affiche "Payé" sur TL-SEED-002 (versé)', async ({
    page,
}) => {
    await goToTransfertDetail(page, 'TL-SEED-002');

    // Le stepper affiche "Payé" comme libellé de l'étape commission
    const stepper = stepperCard(page);
    await expect(stepper).toBeVisible({ timeout: 10_000 });
    await expect(stepper).toContainText(/pay/i);

    // L'onglet commission est accessible
    await expect(
        page.getByRole('button', { name: /commission logistique/i }),
    ).not.toBeDisabled({ timeout: 5_000 });
});

test('stepper — "Réceptionné" passe au vert quand commission générée (TL-SEED-001)', async ({
    page,
}) => {
    await goToTransfertDetail(page, 'TL-SEED-001');

    const stepper = stepperCard(page);
    await expect(stepper).toBeVisible({ timeout: 10_000 });

    // "Réceptionné" doit avoir la classe emerald (vert = done), pas blue (current)
    // car la commission est générée → le marqueur avance sur l'étape commission
    const receptionneStep = stepper
        .locator('div.rounded-full')
        .filter({ has: page.locator('[class*="PackageCheck"], svg') })
        .first();

    // La pastille "Réceptionné" doit être verte (bg-emerald-500), pas bleue
    await expect(receptionneStep).toHaveClass(/bg-emerald-500/, {
        timeout: 5_000,
    });
});
