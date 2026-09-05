import { expect, test } from '@playwright/test';
import {
    configurerBaremes,
    configurerPartageVehicule,
    confirmAlertDialog,
    creerVenteEtEncaisser,
    montantPattern,
} from './commission-v2-helpers';
import { loginAsElmV2Demo, selectOptionFromCombobox } from './helpers';

/**
 * Parcours direct depuis Comptabilité > Commissions > Ventes, sans passer par le menu Périodes
 * (cf. décision produit du 29/08/2026 : le comptable ne doit plus avoir besoin de connaître une
 * référence de période pour traiter une commission). Réutilise exactement la mise en place
 * partagée avec commission-v2-full-chain.spec.ts (mêmes barèmes/partage/vente/encaissement,
 * cf. commission-v2-helpers.ts) jusqu'à l'obtention d'une commission "À valider", puis exerce
 * les deux actions ajoutées sur cet écran :
 *
 *   Valider directement (checkbox/bouton, sans écran Ajustement véhicule)
 *   Ajuster (drawer, motif obligatoire, montant théorique conservé)
 *
 * Le parcours "Périodes" classique reste couvert par commission-v2-full-chain.spec.ts — non
 * dupliqué ici.
 */

test.setTimeout(180_000);

test.beforeEach(async ({ page }) => {
    await loginAsElmV2Demo(page);
});

async function amenerCommissionAValider(page: import('@playwright/test').Page) {
    await configurerBaremes(page);
    await configurerPartageVehicule(page);
    await creerVenteEtEncaisser(page);

    await page.goto('/backoffice/comptabilite/commissions/vente');
    // "Commissions des livreurs" depuis la fusion Vente/Logistique du 04/09/2026
    // (cf. docblock de commission-detail-pages.spec.ts) — plus de mention "sur les ventes".
    await expect(
        page.getByRole('heading', {
            name: /^commissions des livreurs$/i,
        }),
    ).toBeVisible({ timeout: 15_000 });

    const row = page
        .locator('tbody tr', { hasText: /chauffeur v2 demo/i })
        .first();
    await expect(row).toBeVisible({ timeout: 15_000 });
    await expect(row.getByText(/^à valider$/i)).toBeVisible({
        timeout: 10_000,
    });

    return row;
}

test('Commissions > Ventes — valider directement une ligne "À valider" sans passer par Périodes', async ({
    page,
}) => {
    const row = await amenerCommissionAValider(page);

    const validerBtn = row.getByRole('button', {
        name: 'Valider',
        exact: true,
    });
    await expect(validerBtn).toBeVisible({ timeout: 10_000 });
    await validerBtn.click();

    await confirmAlertDialog(page, 'Valider');

    // La ligne reste "À valider" (rien n'est encore payable — seule la validation de la période
    // active le paiement), mais l'action Valider a été consommée : le bouton disparaît, preuve
    // que la part a bien été pré-validée côté serveur (validated_at) sans jamais transiter par
    // l'écran Périodes/Ajustement véhicule.
    await expect(validerBtn).toBeHidden({ timeout: 15_000 });
    await expect(row.getByText(/^à valider$/i)).toBeVisible();
});

test('Commissions > Ventes — ajuster une commission (motif obligatoire, montant théorique conservé)', async ({
    page,
}) => {
    const row = await amenerCommissionAValider(page);

    const genereCell = row.locator('td').nth(4);
    const genereText = (await genereCell.innerText()).trim();
    const montantCalcule = Number(genereText.replace(/[^\d]/g, ''));
    expect(montantCalcule).toBeGreaterThan(0);

    await row.locator('button').last().click();
    await page.getByRole('menuitem', { name: /^ajuster$/i }).click();

    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /^ajuster/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    await expect(
        dialog.getByText(new RegExp(montantPattern(montantCalcule))),
    ).toBeVisible();

    const ajusterSubmit = dialog.getByRole('button', { name: /^ajuster$/i });

    // Motif obligatoire : le bouton reste désactivé tant qu'aucun motif n'est choisi, même
    // avec un ajustement renseigné.
    const ajustementInput = dialog.locator('input').first();
    await ajustementInput.click({ clickCount: 3 });
    await ajustementInput.pressSequentially('-100', { delay: 30 });
    await ajustementInput.blur();
    await expect(ajusterSubmit).toBeDisabled();

    const motifCombobox = dialog.getByRole('combobox').first();
    await selectOptionFromCombobox(page, motifCombobox, /correction/i);
    await expect(ajusterSubmit).toBeEnabled({ timeout: 5_000 });

    const montantRetenuAttendu = montantCalcule - 100;
    await expect(
        dialog.getByText(new RegExp(montantPattern(montantRetenuAttendu))),
    ).toBeVisible();

    await ajusterSubmit.click();
    await expect(dialog).toBeHidden({ timeout: 15_000 });

    // Le montant "Généré" (théorique) reste inchangé — seul le montant retenu (Net à payer)
    // bouge : c'est exactement la garantie backend testée dans
    // CommissionValidationDirecteTest::ajuster_directement_conserve_le_montant_theorique_...
    await expect(row.locator('td').nth(4)).toContainText(
        new RegExp(montantPattern(montantCalcule)),
    );
    await expect(row.locator('td').nth(7)).toContainText(
        new RegExp(montantPattern(montantRetenuAttendu)),
    );
});
