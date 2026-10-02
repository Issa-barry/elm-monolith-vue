import { expect, type Page } from '@playwright/test';
import { configurerBareme } from './commissions/helpers';
import {
    configurerPartageEquipe,
    creerCommande,
    demarrerEtValiderChargement,
    encaisserFacture,
} from './commissions/vente-workflow';

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

/**
 * Paramètres → Commissions : Propriétaire ET Livreur sur la catégorie de démo, Site et
 * Consultant décochés (état laissé par d'autres specs sur la même organisation). Délègue au
 * helper écrit contre l'écran actuel (dialog par catégorie + « Vérifier et enregistrer »,
 * reconfiguration groupée terminée si le barème Livreur change).
 */
export async function configurerBaremes(page: Page): Promise<void> {
    await configurerBareme(page, {
        categorieNom: CATEGORIE_NOM,
        montants: {
            proprietaire: PROPRIETAIRE_MONTANT,
            livreur: LIVRAISON_MONTANT,
            site: null,
            consultant: null,
        },
    });
}

/** Popup équipe du véhicule de démo : chauffeur unique, répartition complète, enregistrée. */
export async function configurerPartageVehicule(page: Page): Promise<void> {
    await configurerPartageEquipe(
        page,
        new RegExp(VEHICULE_IMMAT, 'i'),
        CATEGORIE_NOM,
        LIVRAISON_MONTANT,
    );
}

/** Commande → confirmation → chargement → encaissement intégral de la facture. */
export async function creerVenteEtEncaisser(page: Page): Promise<void> {
    await creerCommande(page, new RegExp(VEHICULE_IMMAT, 'i'));
    await demarrerEtValiderChargement(page);
    // /backoffice/factures affiche le nom du véhicule (ElmV2DemoFleetSeeder), pas l'immatriculation.
    await encaisserFacture(page, /véhicule v2 demo/i);
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
