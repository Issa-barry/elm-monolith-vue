import { expect, test } from '@playwright/test';
import { login } from './helpers';

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
 */
test('paiement partiel commission logistique — Déjà payé et Reste à payer se mettent à jour', async ({
    page,
}) => {
    await login(page);

    await page.goto('/logistique/commissions');
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

    // ── 2. Ouvrir le menu et cliquer « Payer » ─────────────────────────────────
    await row.locator('button').last().click();
    const payerItem = page.getByRole('menuitem', { name: /payer/i }).first();
    await expect(payerItem).toBeVisible({ timeout: 5_000 });
    await payerItem.click();

    // ── 3. Remplir le dialog — paiement partiel de 2 000 GNF ──────────────────
    const dialog = page.locator('[role="dialog"]').filter({ hasText: /Aissatou/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    const montantInput = dialog.locator('input').first();
    await expect(montantInput).toBeVisible({ timeout: 10_000 });
    await montantInput.fill('2000');
    await montantInput.press('Tab');

    const confirmerBtn = dialog.getByRole('button', { name: /confirmer le paiement/i });
    await expect(confirmerBtn).toBeEnabled({ timeout: 5_000 });
    await confirmerBtn.click();

    // ── 4. Vérifier après paiement partiel ────────────────────────────────────
    // « Déjà payé » doit afficher 2 000 GNF (régression : restait à 0 GNF avant fix)
    await expect(colPaye).toContainText(/2[\s ]000/, { timeout: 20_000 });
    // « Reste à payer » doit afficher 9 200 GNF
    await expect(colReste).toContainText(/9[\s ]200/);

    // ── 5. Payer le solde restant ──────────────────────────────────────────────
    await row.locator('button').last().click();
    const payerItem2 = page.getByRole('menuitem', { name: /payer/i }).first();
    await expect(payerItem2).toBeVisible({ timeout: 5_000 });
    await payerItem2.click();

    const dialog2 = page.locator('[role="dialog"]').filter({ hasText: /Aissatou/i });
    await expect(dialog2).toBeVisible({ timeout: 10_000 });

    // Le solde pre-rempli doit être 9 200 GNF — laisser tel quel et confirmer
    const confirmerBtn2 = dialog2.getByRole('button', { name: /confirmer le paiement/i });
    await expect(confirmerBtn2).toBeEnabled({ timeout: 5_000 });
    await confirmerBtn2.click();

    // ── 6. Vérifier que tout est payé ─────────────────────────────────────────
    // « Reste à payer » doit être 0 GNF
    await expect(colReste).toContainText(/0\s*GNF/, { timeout: 20_000 });
    // « Déjà payé » doit afficher 11 200 GNF
    await expect(colPaye).toContainText(/11[\s ]200/);
});

test('livreur déjà payé intégralement — Reste à payer = 0 GNF', async ({
    page,
}) => {
    await login(page);

    await page.goto('/logistique/commissions');
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
