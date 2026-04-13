import { expect, test } from '@playwright/test';
import { login } from './helpers';

test.setTimeout(240_000);

async function selectFirstVehicule(page: Parameters<typeof login>[0]) {
    const vehiculeAutocomplete = page.locator('#vente-form .p-autocomplete').first();

    await expect(vehiculeAutocomplete).toBeVisible({ timeout: 15_000 });
    await vehiculeAutocomplete.locator('button').first().click({ timeout: 5_000 });

    const firstOption = page.locator('[role="option"]:visible').first();
    await expect(firstOption).toBeVisible({ timeout: 10_000 });

    const optionText = (await firstOption.innerText()).trim();
    const vehiculeNom = optionText.split('\n')[0]?.trim() ?? optionText;

    await firstOption.click({ timeout: 5_000 });

    return vehiculeNom;
}

test('commande -> validation -> encaissement facture -> visible dans /factures', async ({
    page,
}) => {
    await login(page);

    // ── 1. Créer une commande ──────────────────────────────────────────────────
    await page.goto('/ventes/create');
    await expect(page).toHaveURL(/\/ventes\/create$/, { timeout: 20_000 });

    const vehiculeNom = await selectFirstVehicule(page);

    const submitCreate = page.locator('#vente-form button[type="submit"]:visible').first();
    await expect(submitCreate).toBeEnabled({ timeout: 10_000 });
    await submitCreate.click();

    await expect(page).toHaveURL(/\/ventes\/\d+$/, { timeout: 30_000 });

    // ── 2. Valider la commande ─────────────────────────────────────────────────
    const validerBtn = page.getByRole('button', { name: /valider la commande/i }).first();
    await expect(validerBtn).toBeVisible({ timeout: 20_000 });
    await validerBtn.click();

    // Attendre le toast de confirmation de validation
    await expect(page.locator('body')).toContainText(/facture créée|validée/i, {
        timeout: 30_000,
    });

    // ── 3. Aller sur /factures et encaisser ────────────────────────────────────
    await page.goto('/factures');
    await expect(page).toHaveURL(/\/factures/, { timeout: 20_000 });
    await expect(page.locator('body')).toContainText(/factures de vente/i, {
        timeout: 20_000,
    });

    // Trouver la ligne de la facture correspondant au véhicule
    const row = page.locator('tbody tr, [data-testid="facture-row"]', {
        hasText: new RegExp(vehiculeNom, 'i'),
    }).first();
    await expect(row).toBeVisible({ timeout: 20_000 });

    // Cliquer sur le bouton "Encaisser" de la ligne
    const encaisserBtn = row.getByRole('button', { name: /encaisser/i }).first();
    await expect(encaisserBtn).toBeVisible({ timeout: 10_000 });
    await encaisserBtn.click();

    // ── 4. Remplir le dialog encaissement ─────────────────────────────────────
    const dialog = page.locator('[role="dialog"]').filter({ hasText: /encaissement/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    // Remplir le montant (premier input numérique du dialog)
    const montantInput = dialog.locator('input').first();
    await expect(montantInput).toBeVisible({ timeout: 10_000 });
    await montantInput.fill('1000');
    await montantInput.press('Tab');

    // Soumettre
    const validerEncaissement = dialog.getByRole('button', {
        name: /valider l'encaissement/i,
    });
    await expect(validerEncaissement).toBeEnabled({ timeout: 5_000 });
    await validerEncaissement.click();

    // ── 5. Vérifier le statut mis à jour ──────────────────────────────────────
    await expect(row).toContainText(/partiel|pay/i, { timeout: 20_000 });
});
