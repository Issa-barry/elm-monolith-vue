import { expect, test } from '@playwright/test';
import { login } from './helpers';

test.setTimeout(240_000);

async function selectFirstVehicule(page: Parameters<typeof login>[0]) {
    const vehiculeAutocomplete = page
        .locator('#vente-form .p-autocomplete')
        .first();

    await expect(vehiculeAutocomplete).toBeVisible({ timeout: 15_000 });
    await vehiculeAutocomplete
        .locator('button')
        .first()
        .click({ timeout: 5_000 });

    const firstOption = page.locator('[role="option"]:visible').first();
    await expect(firstOption).toBeVisible({ timeout: 10_000 });

    const optionText = (await firstOption.innerText()).trim();
    const vehiculeNom = optionText.split('\n')[0]?.trim() ?? optionText;

    await firstOption.click({ timeout: 5_000 });

    return vehiculeNom;
}

test('commande -> confirmation -> chargement -> encaissement facture -> visible dans /factures', async ({
    page,
}) => {
    await login(page);

    // ── 1. Créer une commande ──────────────────────────────────────────────────
    await page.goto('/backoffice/ventes/create');
    await expect(page).toHaveURL(/\/ventes\/create$/, { timeout: 20_000 });

    const vehiculeNom = await selectFirstVehicule(page);

    const submitCreate = page
        .locator('#vente-form button[type="submit"]:visible')
        .first();
    await expect(submitCreate).toBeEnabled({ timeout: 10_000 });
    await submitCreate.click();

    // La soumission ouvre un dialog de confirmation — cliquer "Confirmer et créer"
    const confirmerEtCreerBtn = page.getByRole('button', {
        name: /confirmer et créer/i,
    });
    await expect(confirmerEtCreerBtn).toBeVisible({ timeout: 10_000 });
    await confirmerEtCreerBtn.click();

    // "create" est alphanumérique et matcherait le regex — exclure explicitement
    await expect(page).toHaveURL(/\/ventes\/(?!create)[a-z0-9]+$/, {
        timeout: 30_000,
    });

    // ── 2. Confirmer si BROUILLON (sinon auto-confirmée à la création) ─────────
    // Quand vehicule + lignes sont présents, le controller auto-confirme la commande
    // dès la création (BROUILLON → A_CHARGER). Le bouton n'apparaît que si la
    // commande est restée en BROUILLON (ex: commande sans véhicule).
    const confirmerBtn = page
        .getByRole('button', { name: /^confirmer$/i })
        .first();
    const estEnBrouillon = await confirmerBtn
        .isVisible({ timeout: 5_000 })
        .catch(() => false);
    if (estEnBrouillon) {
        await confirmerBtn.click();
    }

    // ── 3. Démarrer le chargement (A_CHARGER → CHARGEMENT_EN_COURS) ───────────
    // "Démarrer le chargement" appears only after A_CHARGER state is set —
    // waiting for it serves as both the post-confirm state assertion and the button.
    const demarrerBtn = page
        .getByRole('button', { name: /démarrer le chargement/i })
        .first();
    await expect(demarrerBtn).toBeVisible({ timeout: 20_000 });
    await demarrerBtn.click();

    // Attendre que la facture soit créée (toast PrimeVue visible dans le body)
    await expect(page.locator('body')).toContainText(
        /facture.*créée|chargement démarré/i,
        {
            timeout: 30_000,
        },
    );

    // ── 3b. Valider le chargement (CHARGEMENT_EN_COURS → LIVRAISON_EN_COURS) ──
    const validerChargementBtn = page
        .getByRole('button', { name: /valider le chargement/i })
        .first();
    await expect(validerChargementBtn).toBeVisible({ timeout: 20_000 });
    await validerChargementBtn.click();

    // Le dialog s'ouvre avec les quantités pré-remplies — soumettre directement
    const chargementDialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /renseignez les quantités/i });
    await expect(chargementDialog).toBeVisible({ timeout: 10_000 });
    await chargementDialog
        .getByRole('button', { name: /valider le chargement/i })
        .click();

    await expect(page.locator('body')).toContainText(
        /chargement validé|livraison/i,
        { timeout: 30_000 },
    );

    // ── 4. Aller sur /factures et encaisser ────────────────────────────────────
    await page.goto('/backoffice/factures');
    await expect(page).toHaveURL(/\/factures/, { timeout: 20_000 });
    await expect(page.locator('body')).toContainText(/factures de vente/i, {
        timeout: 20_000,
    });

    // Trouver la ligne de la facture correspondant au véhicule
    const row = page
        .locator('tbody tr', {
            hasText: new RegExp(vehiculeNom, 'i'),
        })
        .first();
    await expect(row).toBeVisible({ timeout: 20_000 });

    // Sur desktop le bouton "Encaisser" est dans le dropdown MoreVertical (dernier bouton de la ligne)
    await row.locator('button').last().click();

    // Attendre le menu contextuel et cliquer sur "Encaisser"
    const encaisserItem = page
        .getByRole('menuitem', { name: /encaisser/i })
        .first();
    await expect(encaisserItem).toBeVisible({ timeout: 5_000 });
    await encaisserItem.click();

    // ── 5. Remplir le dialog encaissement ─────────────────────────────────────
    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /encaisser/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    // Remplir le montant (premier input numérique du dialog)
    const montantInput = dialog.locator('input').first();
    await expect(montantInput).toBeVisible({ timeout: 10_000 });
    await montantInput.fill('1000');
    await montantInput.press('Tab');

    // Soumettre
    const validerEncaissement = dialog.getByRole('button', {
        name: /confirmer le paiement/i,
    });
    await expect(validerEncaissement).toBeEnabled({ timeout: 5_000 });
    await validerEncaissement.click();

    // ── 6. Vérifier le statut mis à jour ──────────────────────────────────────
    await expect(row).toContainText(/partiel|pay/i, { timeout: 20_000 });
});
