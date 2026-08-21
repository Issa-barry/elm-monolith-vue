import { expect, type Page, test } from '@playwright/test';
import { loginAsElmV2Demo, selectOptionFromCombobox } from './helpers';

/**
 * Parcours complet du moteur de commissions V2, de bout en bout via l'UI,
 * sur l'organisation dédiée "Eau La Maman V2 Demo" (cf. ElmV2DemoSeeder) —
 * seule organisation du projet avec le processus vente V2 activé, pour ne
 * jamais faire basculer les specs Legacy existantes (organisation "elm").
 *
 * Aucun accès direct à la base de données : le seul état pré-existant est
 * celui posé par le seeder (organisation, site, véhicule + équipe SANS
 * partage, catégorie + produit SANS barème, stock). Barèmes et partage sont
 * configurés par ce test lui-même, exactement comme un administrateur le
 * ferait :
 *
 *   configurer barèmes (Paramètres → Commissions)
 *   → configurer partage véhicule (popup équipe, étape Partage)
 *   → créer vente → encaisser
 *   → commission générée (vérifiée en base via l'écran Commission vente)
 *   → calculer période → valider période
 *   → fiche créée → payer
 *   → reste à payer = 0
 */

test.setTimeout(180_000);

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
function montantPattern(amount: number): string {
    const formatted = new Intl.NumberFormat('fr-FR').format(amount);
    return formatted
        .split('')
        .map((ch) => (/\s/.test(ch) ? '\\s' : ch))
        .join('');
}

test.beforeEach(async ({ page }) => {
    await loginAsElmV2Demo(page);
});

/** Paramètres → Commissions : définit Propriétaire ET Livraison sur la ligne de la catégorie de démo. */
async function configurerBaremes(page: Page): Promise<void> {
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
async function configurerPartageVehicule(page: Page): Promise<void> {
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
    await expect(
        dialog.getByText(
            new RegExp(
                `${montantPattern(LIVRAISON_MONTANT)}.*unité.*Livraison`,
                'i',
            ),
        ),
    ).toBeVisible();

    // Un seul chauffeur : auto-complété à 100 % (cf. initPartagesParCategorie()).
    await expect(dialog.getByText('100 %').first()).toBeVisible({
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
async function creerVenteEtEncaisser(page: Page): Promise<void> {
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

    const confirmerEtCreerBtn = page.getByRole('button', {
        name: /confirmer et créer/i,
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

/** Confirme (au niveau UI) que la commission générée est bien visible sur Commission vente. */
async function verifierCommissionVisible(page: Page): Promise<void> {
    await page.goto('/backoffice/comptabilite/commissions/vente');
    await expect(
        page.getByRole('heading', {
            name: /commissions des livreurs sur les ventes/i,
        }),
    ).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText(/chauffeur v2 demo/i).first()).toBeVisible({
        timeout: 15_000,
    });

    const summaryCards = page
        .getByTestId('commission-summary-cards')
        .locator(':scope > div');
    await expect(summaryCards).toHaveCount(4);
    await expect(
        page
            .getByTestId('commission-summary-cards')
            .getByText('Dépenses', { exact: true }),
    ).toBeVisible();

    await page.getByRole('button', { name: 'Exporter', exact: true }).click();
    await expect(
        page.getByRole('menuitem', { name: 'Exporter en Excel' }),
    ).toBeVisible();
    await expect(
        page.getByRole('menuitem', { name: 'Exporter en PDF' }),
    ).toBeVisible();
    await page.keyboard.press('Escape');

    await page.getByRole('button', { name: /^Filtres/ }).click();
    await expect(page.getByTestId('filters-drawer')).toBeVisible();
    await expect(page.getByTestId('agency-filter')).toBeVisible();
    await page.getByTestId('filters-drawer-close').click();

    const tableScroll = page.getByTestId('commission-table-scroll');
    await expect(tableScroll).toBeVisible();
    expect(
        await tableScroll.evaluate(
            (element) => element.scrollWidth > element.clientWidth,
        ),
    ).toBe(true);
}

/** Ouvre la période Livreurs courante (auto-calculée à l'ouverture) puis la valide. */
async function validerPeriodeLivreurCourante(page: Page): Promise<void> {
    await page.goto('/backoffice/comptabilite/periodes');
    const livreurLink = page.getByRole('link', { name: /livreurs/i }).first();
    await expect(livreurLink).toBeVisible({ timeout: 15_000 });
    await livreurLink.click();
    await page.waitForURL(/\/comptabilite\/periodes\/[a-z0-9]+$/, {
        timeout: 20_000,
    });
    const periodeUrl = page.url();

    // La page auto-calcule à l'ouverture (calculerSiNecessaire) — attendre
    // que le véhicule de démo apparaisse avant de tenter de le valider.
    const ajusterLink = page.getByRole('link', { name: /ajuster/i }).first();
    await expect(ajusterLink).toBeVisible({ timeout: 20_000 });
    await ajusterLink.click();
    await page.waitForURL(/\/ajustements\/vehicules\//, { timeout: 20_000 });

    const validerVehiculeBtn = page.getByRole('button', {
        name: /valider le véhicule/i,
    });
    await expect(validerVehiculeBtn).toBeVisible({ timeout: 15_000 });
    await validerVehiculeBtn.click();
    await confirmAlertDialog(page, 'Valider');

    await page.goto(periodeUrl);
    const validerPeriodeBtn = page.getByRole('button', {
        name: 'Valider',
        exact: true,
    });
    await expect(validerPeriodeBtn).toBeVisible({ timeout: 15_000 });
    await validerPeriodeBtn.click();
    await confirmAlertDialog(page, 'Valider');
}

async function confirmAlertDialog(
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

/** Paie intégralement la fiche du chauffeur de démo, puis vérifie reste à payer = 0. */
async function payerFicheEtVerifierSolde(page: Page): Promise<void> {
    await page.goto('/backoffice/comptabilite/fiches/livreurs');

    const ficheLink = page
        .getByRole('link', { name: /chauffeur v2 demo/i })
        .first();
    await expect(ficheLink).toBeVisible({ timeout: 20_000 });
    await ficheLink.click();
    await page.waitForURL(/\/comptabilite\/fiches\/[a-z0-9]+$/, {
        timeout: 20_000,
    });

    const paiementHeading = page.getByRole('heading', {
        name: /enregistrer un paiement/i,
    });
    await expect(paiementHeading).toBeVisible({ timeout: 15_000 });

    const modeCombobox = page.locator('form').getByRole('combobox').first();
    await expect(modeCombobox).toBeVisible({ timeout: 10_000 });
    await selectOptionFromCombobox(page, modeCombobox, /esp[eè]ces/i);

    const submitBtn = page.getByRole('button', {
        name: /enregistrer le paiement/i,
    });
    await expect(submitBtn).toBeVisible({ timeout: 10_000 });
    await submitBtn.click();

    // Le montant pré-rempli au solde restant règle la fiche intégralement : le
    // formulaire de paiement disparaît une fois can_pay redevenu false.
    await expect(paiementHeading).toBeHidden({ timeout: 20_000 });

    // Reste à payer = 0 — le résumé de la fiche l'affiche explicitement, dans un
    // seul span "Reste : {montant}" (cf. Fiches/Show.vue), pas "reste à payer" et
    // pas un span isolé à "0 GNF" (le libellé fait partie du même nœud de texte).
    await expect(page.getByText(/reste\s*:\s*0\s*GNF/i)).toBeVisible({
        timeout: 10_000,
    });
}

test('vente V2 encaissée → commission générée → période validée → fiche payée → reste à payer = 0', async ({
    page,
}) => {
    await configurerBaremes(page);
    await configurerPartageVehicule(page);
    await creerVenteEtEncaisser(page);
    await verifierCommissionVisible(page);
    await validerPeriodeLivreurCourante(page);
    await payerFicheEtVerifierSolde(page);
});
