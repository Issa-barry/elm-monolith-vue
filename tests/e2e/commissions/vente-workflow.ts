import { expect, type Page } from '@playwright/test';
import { montantPattern } from './helpers';

/**
 * Flux Vente V2 générique (création → confirmation → chargement → facture →
 * encaissement), extrait et généralisé depuis l'ancien
 * tests/e2e/commission-v2-full-chain.spec.ts::creerVenteEtEncaisser() — celui-ci
 * ne gérait qu'un seul véhicule (premier item de l'autocomplete) et un encaissement
 * toujours complet ; ce module paramètre le véhicule (nécessaire depuis l'ajout du
 * Tricycle de démo, cf. ElmV2DemoCommissionSmokeFixturesSeeder) et le montant encaissé
 * (nécessaire pour les scénarios encaissement partiel).
 */

export async function confirmAlertDialog(
    page: Page,
    buttonLabel: RegExp | string,
): Promise<void> {
    const dialog = page.locator('[role="alertdialog"]').last();
    await expect(dialog).toBeVisible({ timeout: 10_000 });
    const acceptButton = dialog.getByRole('button', { name: buttonLabel }).last();
    await expect(acceptButton).toBeVisible({ timeout: 5_000 });
    await acceptButton.click();
    await dialog.waitFor({ state: 'hidden', timeout: 15_000 }).catch(() => {});
}

/** Crée une commande pour le véhicule dont l'immatriculation/nom matche `vehiculeMatch`, confirme, retourne son id. */
export async function creerCommande(
    page: Page,
    vehiculeMatch: string | RegExp,
): Promise<string> {
    await page.goto('/backoffice/ventes/create');
    await expect(page).toHaveURL(/\/ventes\/create$/, { timeout: 20_000 });

    const vehiculeAutocomplete = page.locator('#vente-form .p-autocomplete').first();
    await expect(vehiculeAutocomplete).toBeVisible({ timeout: 15_000 });
    await vehiculeAutocomplete.locator('button').first().click({ timeout: 5_000 });

    const option = page
        .locator('[role="option"]:visible', { hasText: vehiculeMatch })
        .first();
    await expect(option).toBeVisible({ timeout: 10_000 });
    await option.click({ timeout: 5_000 });

    const submitCreate = page.locator('#vente-form button[type="submit"]:visible').first();
    await expect(submitCreate).toBeEnabled({ timeout: 10_000 });
    await submitCreate.click();

    // Libellé dynamique ("Créer la commande"/"Créer la distribution", cf.
    // Create.vue::confirmationActionLabel), scopé au dialog (régression E2E corrigée le 31/08/2026).
    const confirmerEtCreerBtn = page
        .getByRole('dialog')
        .getByRole('button', { name: /créer la (commande|distribution)/i });
    await expect(confirmerEtCreerBtn).toBeVisible({ timeout: 10_000 });
    await confirmerEtCreerBtn.click();
    await expect(page).toHaveURL(/\/ventes\/(?!create)[a-z0-9]+$/, { timeout: 30_000 });

    const confirmerBtn = page.getByRole('button', { name: /^confirmer$/i }).first();
    if (await confirmerBtn.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await confirmerBtn.click();
    }

    return page.url().match(/\/ventes\/([a-z0-9]+)/i)![1];
}

/**
 * Popup équipe du véhicule `vehiculeMatch` : ouvre "Gérer l'équipe", passe l'étape membres
 * (déjà seedée, un seul chauffeur), vérifie que l'étape Partage propose `categorieNom` (le
 * barème Livraison de cette catégorie doit être > 0, sinon le tout-ou-rien de
 * CommissionPartageLivraisonValidator bloque la génération entière — pas seulement Livreur),
 * accepte la répartition complète auto (chauffeur unique = 100%), enregistre.
 * Généralisation de l'ancien commission-v2-full-chain.spec.ts::configurerPartageVehicule()
 * (qui ne gérait qu'un seul véhicule en dur).
 */
export async function configurerPartageEquipe(
    page: Page,
    vehiculeMatch: string | RegExp,
    categorieNom: string,
    livraisonMontant: number,
): Promise<void> {
    await page.goto('/backoffice/vehicules');
    const vehiculeRow = page.locator('tbody tr', { hasText: vehiculeMatch }).first();
    await expect(vehiculeRow).toBeVisible({ timeout: 15_000 });
    await vehiculeRow.click();
    await page.waitForURL(/\/vehicules\/[a-z0-9]+$/, { timeout: 15_000 });

    await page.locator('aside button').filter({ hasText: /equipe/i }).click();

    const gererBtn = page.getByRole('button', { name: /gérer l'équipe/i }).first();
    await expect(gererBtn).toBeVisible({ timeout: 10_000 });
    await gererBtn.click();

    const dialog = page.locator('[role="dialog"]').filter({ hasText: /équipe/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    await dialog.getByRole('button', { name: /suivant/i }).click();

    await expect(dialog.getByText(new RegExp(categorieNom, 'i'))).toBeVisible({
        timeout: 10_000,
    });
    const categorieRow = dialog
        .getByRole('row')
        .filter({ hasText: new RegExp(categorieNom, 'i') });
    await expect(categorieRow).toContainText(
        new RegExp(
            `${montantPattern(livraisonMontant)}\\s*GNF\\s*/\\s*${montantPattern(livraisonMontant)}\\s*GNF`,
            'i',
        ),
    );
    await expect(dialog.getByText(/répartition complète/i)).toBeVisible({
        timeout: 5_000,
    });

    const suivantBtn = dialog.getByRole('button', { name: /suivant/i });
    await expect(suivantBtn).toBeEnabled({ timeout: 5_000 });
    await suivantBtn.click();

    await dialog.getByRole('button', { name: /enregistrer l'équipe/i }).click();
    await expect(dialog).toBeHidden({ timeout: 15_000 });
}

/** Démarre puis valide le chargement sur la page détail de la commande courante. */
export async function demarrerEtValiderChargement(page: Page): Promise<void> {
    const demarrerBtn = page.getByRole('button', { name: /démarrer le chargement/i }).first();
    await expect(demarrerBtn).toBeVisible({ timeout: 20_000 });
    await demarrerBtn.click();
    await expect(page.locator('body')).toContainText(/facture.*créée|chargement démarré/i, {
        timeout: 30_000,
    });

    const validerChargementBtn = page.getByRole('button', { name: /valider le chargement/i }).first();
    await expect(validerChargementBtn).toBeVisible({ timeout: 20_000 });
    await validerChargementBtn.click();

    const chargementDialog = page.locator('[role="dialog"]').filter({
        hasText: /renseignez les quantités/i,
    });
    await expect(chargementDialog).toBeVisible({ timeout: 10_000 });
    await chargementDialog.getByRole('button', { name: /valider le chargement/i }).click();
    await expect(page.locator('body')).toContainText(/chargement validé|livraison/i, {
        timeout: 30_000,
    });
}

/**
 * Encaisse la facture du véhicule `vehiculeNomMatch` (nom affiché sur /backoffice/factures,
 * pas l'immatriculation). `montant` en dur pour un encaissement partiel ; omis = encaissement
 * complet (resoumet le montant pré-rempli).
 */
export async function encaisserFacture(
    page: Page,
    vehiculeNomMatch: string | RegExp,
    montant?: number,
): Promise<void> {
    await page.goto('/backoffice/factures');
    await expect(page.locator('body')).toContainText(/factures de vente/i, { timeout: 20_000 });

    const row = page.locator('tbody tr', { hasText: vehiculeNomMatch }).first();
    await expect(row).toBeVisible({ timeout: 20_000 });
    await row.locator('button').last().click();

    const encaisserItem = page.getByRole('menuitem', { name: /encaisser/i }).first();
    await expect(encaisserItem).toBeVisible({ timeout: 5_000 });
    await encaisserItem.click();

    const dialog = page.locator('[role="dialog"]').filter({ hasText: /encaisser/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    const montantInput = dialog.locator('input').first();
    await expect(montantInput).toBeVisible({ timeout: 10_000 });

    if (typeof montant === 'number') {
        await montantInput.fill(String(montant));
    } else {
        const montantActuel = await montantInput.inputValue();
        await montantInput.fill(montantActuel.replace(/\D/g, ''));
    }
    await montantInput.press('Tab');

    const validerEncaissement = dialog.getByRole('button', { name: /confirmer le paiement/i });
    await expect(validerEncaissement).toBeEnabled({ timeout: 5_000 });
    await validerEncaissement.click();
    await expect(dialog).toBeHidden({ timeout: 15_000 });
}
