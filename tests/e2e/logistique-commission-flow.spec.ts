import { expect, test, type Page } from '@playwright/test';
import { login, selectOptionFromCombobox } from './helpers';

test.setTimeout(120_000);

/**
 * Regression test for the bug where « Déjà payé » stayed at 0 GNF after a
 * partial payment because soldesParLivreur used SUM(CASE WHEN statut='paye'
 * THEN montant_net ELSE 0 END) instead of SUM(montant_verse).
 *
 * Commission data is created via UI in global-setup.ts (no seeder) :
 *   - Aissatou BALDÉ  : 11 200 GNF impayé  (elm-2, 80 packs × 200 × 70 %)
 *   - Thierno SALL    : 4 800 GNF impayé   (elm-2, 80 packs × 200 × 30 %)
 *   - Boubacar KONATÉ : 24 000 GNF payé    (elm-1, 120 packs × 200 × 100 %)
 *
 * Depuis le verrou anti-double-paiement (PeriodePayabilityChecker::assertPartsNotClaimedByFiche),
 * le paiement DIRECT (dialog "Payer" de cette page) est bloqué dès qu'une fiche existe pour la
 * période — et global-setup.ts valide déjà la période partagée des 3 livreurs ci-dessus, ce qui
 * génère leur fiche automatiquement (PeriodeCalculatorService::creerFiche). Les paiements de ce
 * test passent donc par la fiche (Comptabilité > Fiches) ; seules les colonnes « Reste à payer »/
 * « Déjà payé » de CETTE page (logistique/commissions) sont encore vérifiées ici, pour couvrir
 * CommissionPaymentService::soldesParLivreur() qui doit relire le solde côté fiche.
 */
async function payViaFiche(
    page: Page,
    livreurRegex: RegExp,
    montant?: string,
): Promise<void> {
    await page.goto('/backoffice/comptabilite/fiches/livreurs');

    const ficheLink = page.getByRole('link', { name: livreurRegex }).first();
    await ficheLink.waitFor({ state: 'visible', timeout: 20_000 });
    await ficheLink.click();

    await page.waitForURL(/\/comptabilite\/fiches\/[a-z0-9]+$/, {
        timeout: 20_000,
    });
    await page
        .getByRole('heading', { name: /enregistrer un paiement/i })
        .waitFor({ state: 'visible', timeout: 15_000 });

    if (montant) {
        const montantInput = page.locator('form input[type="number"]').first();
        await montantInput.fill(montant);
    }

    const modeCombobox = page.locator('form').getByRole('combobox').first();
    await modeCombobox.waitFor({ state: 'visible', timeout: 10_000 });
    await selectOptionFromCombobox(page, modeCombobox, /esp[eè]ces/i);

    await page.getByRole('button', { name: /enregistrer le paiement/i }).click();
}

test('paiement partiel commission logistique — Déjà payé et Reste à payer se mettent à jour', async ({
    page,
}) => {
    await login(page);

    await page.goto('/backoffice/logistique/commissions');
    await expect(page).toHaveURL(/\/logistique\/commissions/, { timeout: 20_000 });
    await expect(page.locator('body')).toContainText(/commissions logistiques/i, {
        timeout: 20_000,
    });

    // ── 1. Trouver la ligne d'Aissatou BALDÉ ──────────────────────────────────
    const row = page
        .locator('tbody tr', { hasText: /Aissatou\s+BALD/i })
        .first();
    await expect(row).toBeVisible({ timeout: 20_000 });

    // Colonnes (1-indexed): Livreur | Véhicule | Statut | Total | Reste à payer | Déjà payé | Actions
    const colReste = row.locator('td').nth(4); // "Reste à payer"
    const colPaye  = row.locator('td').nth(5); // "Déjà payé"

    // État initial : 11 200 GNF impayé, 0 GNF payé
    await expect(colReste).toContainText(/11[\s ]200/);
    await expect(colPaye).toContainText(/0\s*GNF/);

    // ── 2. Paiement partiel de 2 000 GNF via la fiche ──────────────────────────
    await payViaFiche(page, /Aissatou\s+BALD/i, '2000');
    // Le formulaire reste affiché (solde non nul) : on attend la mise à jour du
    // libellé "Reste :" de la barre de progression comme signal de fin de paiement.
    await expect(
        page.getByText(/reste\s*:\s*9[\s ]200/i),
    ).toBeVisible({ timeout: 20_000 });

    // ── 3. Vérifier sur /logistique/commissions après paiement partiel ────────
    await page.goto('/backoffice/logistique/commissions');
    // « Déjà payé » doit afficher 2 000 GNF (régression : restait à 0 GNF avant fix)
    await expect(colPaye).toContainText(/2[\s ]000/, { timeout: 20_000 });
    // « Reste à payer » doit afficher 9 200 GNF
    await expect(colReste).toContainText(/9[\s ]200/);

    // ── 4. Payer le solde restant via la fiche (montant pré-rempli) ───────────
    await payViaFiche(page, /Aissatou\s+BALD/i);
    await page
        .getByRole('button', { name: /enregistrer le paiement/i })
        .waitFor({ state: 'hidden', timeout: 20_000 });

    // ── 5. Vérifier que tout est payé ─────────────────────────────────────────
    await page.goto('/backoffice/logistique/commissions');
    // « Reste à payer » doit être 0 GNF
    await expect(colReste).toContainText(/0\s*GNF/, { timeout: 20_000 });
    // « Déjà payé » doit afficher 11 200 GNF
    await expect(colPaye).toContainText(/11[\s ]200/);
});

test('livreur déjà payé intégralement — Reste à payer = 0 GNF', async ({
    page,
}) => {
    await login(page);

    await page.goto('/backoffice/logistique/commissions');
    await expect(page).toHaveURL(/\/logistique\/commissions/, { timeout: 20_000 });

    // Boubacar KONATÉ est payé avec 24 000 GNF (120 packs × 200 × 100 %)
    const row = page
        .locator('tbody tr', { hasText: /Boubacar\s+KONAT/i })
        .first();
    await expect(row).toBeVisible({ timeout: 20_000 });

    const colReste = row.locator('td').nth(4);
    const colPaye  = row.locator('td').nth(5);

    await expect(colReste).toContainText(/0\s*GNF/);
    await expect(colPaye).toContainText(/24[\s ]000/);

    // Le bouton « Payer » ne doit PAS apparaître (solde = 0)
    await row.locator('button').last().click();
    const payerItem = page.getByRole('menuitem', { name: /^payer$/i });
    await expect(payerItem).toBeHidden({ timeout: 3_000 });

    // Fermer le menu
    await page.keyboard.press('Escape');
});
