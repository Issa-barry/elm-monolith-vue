import { expect, type Page } from '@playwright/test';

/**
 * Aides partagées par les parcours E2E du moteur de commissions V2, sur
 * l'organisation dédiée "Eau La Maman V2 Demo" (cf. ElmV2DemoSeeder) : mise en
 * place commune (barèmes, partage véhicule, vente encaissée) et confirmation
 * de dialog, réutilisées par commission-v2-full-chain.spec.ts et
 * commission-validation-directe.spec.ts.
 *
 * Extraites dans ce fichier (suffixe non ".spec") car Playwright interdit
 * qu'un fichier de test en importe un autre (chaque fichier .spec.ts doit
 * rester une unité de collecte indépendante) — sinon erreur "test file ...
 * should not import test file ...".
 */

const CATEGORIE_NOM = "Sachets d'eau V2 Demo";
const VEHICULE_IMMAT = 'V2-DEMO-01';
const PROPRIETAIRE_MONTANT = 500;
const LIVRAISON_MONTANT = 1000;

/**
 * Fragment de regex matchant un montant tel que rendu par `Intl.NumberFormat('fr-FR')`
 * côté front (formatGNF() dans EquipeStepperModal.vue, toLocaleString('fr-FR') ailleurs) :
 * le séparateur de milliers est une espace fine insécable (U+202F), invisible à l'œil mais
 * absente d'un `String(montant)` brut — un `toContainText(String(1000))` ne matche donc
 * jamais "1 000 GNF". Tolère aussi une espace normale, au cas où le rendu diffère.
 */
export function montantPattern(amount: number): string {
    const formatted = new Intl.NumberFormat('fr-FR').format(amount);
    return formatted
        .split('')
        .map((ch) => (/\s/.test(ch) ? '\\s' : ch))
        .join('');
}

/** Paramètres → Commissions : définit Propriétaire ET Livraison sur la ligne de la catégorie de démo. */
export async function configurerBaremes(page: Page): Promise<void> {
    await page.goto('/settings/commissions');
    await expect(
        page.getByRole('heading', { name: /^commissions$/i }),
    ).toBeVisible({ timeout: 15_000 });

    const row = page
        .locator('tbody tr', { hasText: new RegExp(CATEGORIE_NOM, 'i') })
        .first();
    await expect(row).toBeVisible({ timeout: 15_000 });

    const cells = row.getByRole('button');

    await cells.first().click();
    const dialogProp = page.getByRole('dialog', { name: /propriétaire/i });
    await expect(dialogProp).toBeVisible({ timeout: 5_000 });
    await dialogProp.locator('#cr-montant').fill(String(PROPRIETAIRE_MONTANT));
    await dialogProp.getByRole('button', { name: /enregistrer/i }).click();
    await expect(dialogProp).toBeHidden({ timeout: 10_000 });

    await cells.nth(1).click();
    const dialogLiv = page.getByRole('dialog', { name: /livraison/i });
    await expect(dialogLiv).toBeVisible({ timeout: 5_000 });
    await dialogLiv.locator('#cr-montant').fill(String(LIVRAISON_MONTANT));
    await dialogLiv.getByRole('button', { name: /enregistrer/i }).click();
    await expect(dialogLiv).toBeHidden({ timeout: 10_000 });

    await expect(cells.first()).toContainText(
        new RegExp(montantPattern(PROPRIETAIRE_MONTANT)),
    );
    await expect(cells.nth(1)).toContainText(
        new RegExp(montantPattern(LIVRAISON_MONTANT)),
    );
}

/**
 * Popup équipe du véhicule de démo : ouvre "Gérer l'équipe", vérifie que
 * l'étape Partage propose bien la catégorie (barème Livraison > 0
 * maintenant configuré) avec le chauffeur unique auto-complété à 100 %, et
 * enregistre.
 */
export async function configurerPartageVehicule(page: Page): Promise<void> {
    await page.goto('/backoffice/vehicules');
    const vehiculeRow = page
        .locator('tbody tr', { hasText: new RegExp(VEHICULE_IMMAT, 'i') })
        .first();
    await expect(vehiculeRow).toBeVisible({ timeout: 15_000 });
    await vehiculeRow.click();
    await page.waitForURL(/\/vehicules\/[a-z0-9]+$/, { timeout: 15_000 });

    await page
        .locator('aside button')
        .filter({ hasText: /equipe/i })
        .click();

    const gererBtn = page
        .getByRole('button', { name: /gérer l'équipe/i })
        .first();
    await expect(gererBtn).toBeVisible({ timeout: 10_000 });
    await gererBtn.click();

    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /équipe/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    // Étape 1 : le chauffeur de démo est déjà rempli (seedé) — passer directement.
    await dialog.getByRole('button', { name: /suivant/i }).click();

    // Étape 2 (Partage) : la catégorie ne doit apparaître que parce que son
    // barème Livraison est désormais > 0 (configuré par configurerBaremes()) —
    // c'est exactement le comportement backend/UI validé par les tests Feature
    // (VehiculeBaremesCommissionCategoriesTest, CommissionEnveloppeGeneratorReglesTest).
    await expect(dialog.getByText(new RegExp(CATEGORIE_NOM, 'i'))).toBeVisible({
        timeout: 10_000,
    });
    const categorieRow = dialog
        .getByRole('row')
        .filter({ hasText: new RegExp(CATEGORIE_NOM, 'i') });
    await expect(categorieRow).toContainText(
        new RegExp(
            `${montantPattern(LIVRAISON_MONTANT)}\\s*GNF\\s*/\\s*${montantPattern(LIVRAISON_MONTANT)}\\s*GNF`,
            'i',
        ),
    );

    // Un seul chauffeur : l'enveloppe entière lui revient automatiquement (cf.
    // initPartagesParCategorie()) — plus aucun pourcentage, "Répartition complète"
    // s'affiche dès l'ouverture (montant fixe = LIVRAISON_MONTANT).
    await expect(dialog.getByText(/répartition complète/i)).toBeVisible({
        timeout: 5_000,
    });

    const suivantBtn = dialog.getByRole('button', { name: /suivant/i });
    await expect(suivantBtn).toBeEnabled({ timeout: 5_000 });
    await suivantBtn.click();

    // Étape 3 (Récapitulatif) : enregistrer.
    await dialog.getByRole('button', { name: /enregistrer l'équipe/i }).click();
    await expect(dialog).toBeHidden({ timeout: 15_000 });
}

/** Commande → confirmation → chargement → encaissement (miroir de facture-flow.spec.ts). */
export async function creerVenteEtEncaisser(page: Page): Promise<void> {
    await page.goto('/backoffice/ventes/create');
    await expect(page).toHaveURL(/\/ventes\/create$/, { timeout: 20_000 });

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
    await firstOption.click({ timeout: 5_000 });

    const submitCreate = page
        .locator('#vente-form button[type="submit"]:visible')
        .first();
    await expect(submitCreate).toBeEnabled({ timeout: 10_000 });
    await submitCreate.click();

    // Libellé dynamique ("Créer la commande"/"Créer la distribution", cf.
    // Create.vue::confirmationActionLabel) — "Confirmer et créer" n'existe plus depuis son
    // introduction ; scopé au dialog car le bouton de soumission du formulaire sous-jacent
    // porte désormais le même libellé (régression E2E corrigée le 31/08/2026).
    const confirmerEtCreerBtn = page.getByRole('dialog').getByRole('button', {
        name: /créer la (commande|distribution)/i,
    });
    await expect(confirmerEtCreerBtn).toBeVisible({ timeout: 10_000 });
    await confirmerEtCreerBtn.click();
    await expect(page).toHaveURL(/\/ventes\/(?!create)[a-z0-9]+$/, {
        timeout: 30_000,
    });

    const confirmerBtn = page
        .getByRole('button', { name: /^confirmer$/i })
        .first();
    if (await confirmerBtn.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await confirmerBtn.click();
    }

    const demarrerBtn = page
        .getByRole('button', { name: /démarrer le chargement/i })
        .first();
    await expect(demarrerBtn).toBeVisible({ timeout: 20_000 });
    await demarrerBtn.click();
    await expect(page.locator('body')).toContainText(
        /facture.*créée|chargement démarré/i,
        { timeout: 30_000 },
    );

    const validerChargementBtn = page
        .getByRole('button', { name: /valider le chargement/i })
        .first();
    await expect(validerChargementBtn).toBeVisible({ timeout: 20_000 });
    await validerChargementBtn.click();

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

    // ── Encaissement intégral ───────────────────────────────────────────────
    await page.goto('/backoffice/factures');
    await expect(page.locator('body')).toContainText(/factures de vente/i, {
        timeout: 20_000,
    });

    // La colonne "Véhicule / Client" de /backoffice/factures affiche le nom du
    // véhicule (nom_vehicule, cf. ElmV2DemoFleetSeeder), pas son immatriculation —
    // contrairement aux autres pages de ce parcours (liste véhicules, logistique)
    // qui affichent l'immatriculation. D'où ce regex distinct, pas VEHICULE_IMMAT.
    const row = page
        .locator('tbody tr', { hasText: /véhicule v2 demo/i })
        .first();
    await expect(row).toBeVisible({ timeout: 20_000 });
    await row.locator('button').last().click();

    const encaisserItem = page
        .getByRole('menuitem', { name: /encaisser/i })
        .first();
    await expect(encaisserItem).toBeVisible({ timeout: 5_000 });
    await encaisserItem.click();

    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /encaisser/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    // Montant total pré-rempli par défaut sur ce dialog — s'assurer d'un
    // encaissement COMPLET (nécessaire au déclencheur "facture_encaissee",
    // cf. ElmV2DemoOrganizationSeeder) en resoumettant la valeur affichée.
    const montantInput = dialog.locator('input').first();
    await expect(montantInput).toBeVisible({ timeout: 10_000 });
    const montantActuel = await montantInput.inputValue();
    await montantInput.fill(montantActuel.replace(/\D/g, ''));
    await montantInput.press('Tab');

    const validerEncaissement = dialog.getByRole('button', {
        name: /confirmer le paiement/i,
    });
    await expect(validerEncaissement).toBeEnabled({ timeout: 5_000 });
    await validerEncaissement.click();

    await expect(row).toContainText(/pay/i, { timeout: 20_000 });
}

/**
 * Confirme (au niveau UI) une action passant par le ConfirmDialog PrimeVue
 * partagé (validation véhicule / validation période / validation directe
 * commission) : contrairement au Dialog "classique" utilisé ailleurs dans ce
 * parcours (paiement, réception — role="dialog"), le composant PrimeVue
 * ConfirmDialog rend sa racine avec role="alertdialog". Le bouton
 * d'acceptation porte le même libellé que le bouton qui l'a ouvert, d'où le
 * scope explicite sur le dernier `[role="alertdialog"]` ouvert.
 */
export async function confirmAlertDialog(
    page: Page,
    buttonLabel: RegExp | string,
): Promise<void> {
    const dialog = page.locator('[role="alertdialog"]').last();
    await expect(dialog).toBeVisible({ timeout: 10_000 });
    const acceptButton = dialog
        .getByRole('button', { name: buttonLabel })
        .last();
    await expect(acceptButton).toBeVisible({ timeout: 5_000 });
    await acceptButton.click();
    await dialog.waitFor({ state: 'hidden', timeout: 15_000 }).catch(() => {});
}
