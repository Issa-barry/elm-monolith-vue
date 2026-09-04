/**
 * global-setup.ts
 * Runs once before all tests.
 * Logs in and stores auth state in .auth/user.json.
 * Creates two transferts via UI to generate commission seed data
 * (elm-2 → 80 packs, elm-1 → 120 packs), then pays Boubacar's
 * commission fully so elm-1 reaches CLOTURE.
 * References are saved in .auth/commission-seed.json.
 */
import { chromium, type FullConfig, type Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

import { login, selectOptionFromCombobox } from './helpers';

export default async function globalSetup(config: FullConfig) {
    const baseURL = config.projects[0].use.baseURL ?? 'http://127.0.0.1:8080';

    const authDir = path.join(process.cwd(), '.auth');
    if (!fs.existsSync(authDir)) {
        fs.mkdirSync(authDir, { recursive: true });
    }

    // Certains parcours autonomes (notamment Commission V2) créent leurs
    // propres données et se connectent dans leur beforeEach. Cette option
    // permet de les exécuter isolément sans dépendre du préchargement Legacy.
    if (process.env.E2E_SKIP_GLOBAL_SETUP === '1') {
        fs.writeFileSync(
            path.join(authDir, 'user.json'),
            JSON.stringify({ cookies: [], origins: [] }),
        );

        return;
    }

    const browser = await chromium.launch();
    const context = await browser.newContext({ baseURL });
    const page = await context.newPage();

    try {
        await login(page);
        await context.storageState({ path: path.join(authDir, 'user.json') });

        // elm-2 (80 packs): Aissatou 11 200 GNF + Thierno 4 800 GNF — laissés impayés
        const ref001 = await createTransfertAndGenerateCommission(
            page,
            /elm-2/i,
        );

        // elm-1 (120 packs): Boubacar 24 000 GNF — sera entièrement payé ci-dessous
        const ref002 = await createTransfertAndGenerateCommission(
            page,
            /elm-1/i,
        );

        // Le paiement (direct ou via fiche) est verrouillé tant que la PaiementPeriode
        // couvrant la commission n'est pas VALIDEE (PeriodePayabilityChecker) — il faut
        // valider tous les véhicules de la période courante avant de pouvoir payer qui
        // que ce soit, sinon aucun bouton "Payer" n'apparaît nulle part dans l'UI.
        await validerPeriodeLivreurCourante(page);

        // Payer Boubacar intégralement → déclenche cloturerAutomatiquement() sur elm-1
        await payFullCommission(page, /Boubacar\s+KONAT/i);

        fs.writeFileSync(
            path.join(authDir, 'commission-seed.json'),
            JSON.stringify({ ref001, ref002 }, null, 2),
        );
    } catch (error) {
        await browser.close();
        throw error;
    }

    await browser.close();
}

/**
 * Crée un transfert logistique via l'UI, l'amène jusqu'au statut RECEPTION
 * et génère la commission (montant résolu par CommissionRegle — 200 GNF/pack
 * pour les équipes de livraison, cf. EquipesLivraisonSeeder — plus aucune
 * saisie manuelle depuis le 03/09/2026).
 * Retourne la référence du transfert (ex: "TRF-310826-001").
 */
async function createTransfertAndGenerateCommission(
    page: Page,
    vehicleName: RegExp,
): Promise<string> {
    await page.goto('/backoffice/logistique/creer');
    await page
        .locator('#logistique-form')
        .waitFor({ state: 'visible', timeout: 20_000 });

    const form = page.locator('#logistique-form');

    const siteSourceCombobox = form
        .locator('[data-testid="site-source-field"]')
        .getByRole('combobox');
    if ((await siteSourceCombobox.count()) > 0) {
        await selectOptionFromCombobox(
            page,
            siteSourceCombobox,
            /cba|lansanaya|lambagny|dabompa/i,
        );
    }

    const siteDestCombobox = form
        .locator('[data-testid="site-destination-field"]')
        .getByRole('combobox');
    await selectOptionFromCombobox(page, siteDestCombobox);

    const vehiculeCombobox = form
        .locator('[data-testid="vehicule-field"]')
        .getByRole('combobox');
    await selectOptionFromCombobox(page, vehiculeCombobox, vehicleName);

    const submit = form.locator('button[type="submit"]:visible').first();
    await submit.waitFor({ state: 'visible', timeout: 15_000 });
    await submit.click();

    await page.waitForURL(/\/logistique\/[a-z0-9]+$/, { timeout: 30_000 });

    // Extract reference displayed as "N° transfert : TRF-JJMMAA-NNN" (format
    // PREFIXE-JJMMAA-NNN généralisé le 31/08/2026, cf. ReferenceNumeroService —
    // remplace l'ancien "TR-XXXXX-YYY").
    const refElement = page.locator(':text("N° transfert")').first();
    await refElement.waitFor({ state: 'visible', timeout: 10_000 });
    const refText = (await refElement.textContent()) ?? '';
    const refMatch = refText.match(/TRF-\d{6}-\d{3}/i);
    if (!refMatch) {
        throw new Error(
            `Cannot extract transfert reference from page text: "${refText}"`,
        );
    }
    const reference = refMatch[0].toUpperCase();

    // ── Brouillon → Chargement ────────────────────────────────────────────────
    const btnDemarrer = page.getByRole('button', {
        name: /démarrer le chargement/i,
    });
    await btnDemarrer.waitFor({ state: 'visible', timeout: 15_000 });
    await btnDemarrer.click();

    // ── Chargement → Transit (via dialog) ────────────────────────────────────
    const btnValiderChargement = page.getByRole('button', {
        name: /valider le chargement/i,
    });
    await btnValiderChargement.waitFor({ state: 'visible', timeout: 20_000 });
    await btnValiderChargement.click();

    const btnLivraison = page.getByRole('button', {
        name: /valider et partir en livraison/i,
    });
    await btnLivraison.waitFor({ state: 'visible', timeout: 10_000 });
    await btnLivraison.click();

    // ── Transit → Réception (via dialog) ─────────────────────────────────────
    const btnMainReception = page
        .getByRole('button', { name: /valider la réception/i })
        .first();
    await btnMainReception.waitFor({ state: 'visible', timeout: 20_000 });
    await btnMainReception.click();

    // La ReceptionDialog a le même header "Valider la réception" — on cible
    // le bouton submit à l'intérieur du dialog (identifié par son texte unique)
    const receptionDialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /renseignez les quantités/i });
    const btnValiderReception = receptionDialog.getByRole('button', {
        name: /valider la réception/i,
    });
    await btnValiderReception.waitFor({ state: 'visible', timeout: 10_000 });
    await btnValiderReception.click();

    // ── Réception → Commission générée ────────────────────────────────────────
    const btnGenerer = page.getByRole('button', {
        name: /générer commission/i,
    });
    await btnGenerer.waitFor({ state: 'visible', timeout: 20_000 });
    await btnGenerer.click();

    // Confirmer la réception : montant toujours résolu par CommissionRegle
    // (Paramètres > Commissions > Transferts logistiques) depuis le 03/09/2026,
    // plus de saisie manuelle ni d'étape "montant" intermédiaire.
    const btnOuiGenerer = page.getByRole('button', {
        name: /oui, générer la commission/i,
    });
    await btnOuiGenerer.waitFor({ state: 'visible', timeout: 10_000 });
    await btnOuiGenerer.click();

    // Attendre que la commission soit générée (le bouton disparaît)
    await btnGenerer.waitFor({ state: 'hidden', timeout: 20_000 });

    return reference;
}

/**
 * Confirme une action passant par le ConfirmDialog PrimeVue partagé (validation
 * véhicule / validation période) : contrairement au Dialog "classique" utilisé
 * ailleurs dans ce fichier (paiement, réception — role="dialog"), le composant
 * PrimeVue ConfirmDialog rend sa racine avec role="alertdialog" (vérifié dans
 * node_modules/primevue/confirmdialog/index.mjs). Le bouton d'acceptation porte
 * le même libellé que le bouton qui l'a ouvert ("Valider"), d'où le scope
 * explicite sur le dernier `[role="alertdialog"]` ouvert.
 */
async function confirmDialog(
    page: Page,
    buttonLabel: RegExp | string,
): Promise<void> {
    const dialog = page.locator('[role="alertdialog"]').last();
    await dialog.waitFor({ state: 'visible', timeout: 10_000 });
    const acceptButton = dialog
        .getByRole('button', { name: buttonLabel })
        .last();
    await acceptButton.waitFor({ state: 'visible', timeout: 5_000 });
    await acceptButton.click();
    await dialog.waitFor({ state: 'hidden', timeout: 15_000 }).catch(() => {});
}

/**
 * Valide la période de paiement "Livreurs" en cours (commune aux commissions elm-1
 * et elm-2 générées ci-dessus) : condition préalable obligatoire pour que le moindre
 * bouton "Payer" soit visible dans l'UI (PeriodePayabilityChecker impose une période
 * VALIDEE). Valider une période exige d'abord que chaque véhicule qui y a des
 * commissions soit lui-même validé (écart théorique/ajusté nul — toujours vrai ici,
 * aucun ajustement n'étant appliqué dans ce setup).
 */
async function validerPeriodeLivreurCourante(page: Page): Promise<void> {
    await page.goto('/backoffice/comptabilite/periodes');
    const livreurLink = page.getByRole('link', { name: /livreurs/i }).first();
    await livreurLink.waitFor({ state: 'visible', timeout: 15_000 });
    await livreurLink.click();
    await page.waitForURL(/\/comptabilite\/periodes\/[a-z0-9]+$/, {
        timeout: 20_000,
    });
    const periodeUrl = page.url();

    const ajusterLink = page.getByRole('link', { name: /ajuster/i });
    const nbVehicules = await ajusterLink.count();

    for (let i = 0; i < nbVehicules; i++) {
        await page.goto(periodeUrl);
        await page
            .getByRole('link', { name: /ajuster/i })
            .nth(i)
            .click();
        await page.waitForURL(/\/ajustements\/vehicules\//, {
            timeout: 20_000,
        });

        const validerVehiculeBtn = page.getByRole('button', {
            name: /valider le véhicule/i,
        });
        await validerVehiculeBtn.waitFor({ state: 'visible', timeout: 15_000 });
        await validerVehiculeBtn.click();
        await confirmDialog(page, 'Valider');
    }

    await page.goto(periodeUrl);
    const validerPeriodeBtn = page.getByRole('button', {
        name: 'Valider',
        exact: true,
    });
    await validerPeriodeBtn.waitFor({ state: 'visible', timeout: 15_000 });
    await validerPeriodeBtn.click();
    await confirmDialog(page, 'Valider');
}

/**
 * Paie intégralement la commission du livreur correspondant au regex, via sa fiche
 * de paiement (Comptabilité > Fiches). L'écran de paiement DIRECT dédié à la logistique
 * (/backoffice/logistique/commissions) a été retiré le 04/09/2026 (moteur legacy
 * CommissionLogistique/CommissionLogistiquePart gelé depuis le 03/09/2026, cf.
 * docs/commissions.md) ; même quand un canal direct existe encore ailleurs (Comptabilité >
 * Commissions > Logistique), il reste bloqué par PeriodePayabilityChecker::
 * assertPartsNotClaimedByFiche dès qu'une fiche existe pour la période du bénéficiaire — et
 * validerPeriodeLivreurCourante ci-dessus a déjà déclenché la génération automatique des
 * fiches de tous les livreurs de la période (PeriodeCalculatorService::creerFiche, appelé par
 * le show() de la page période). La fiche est donc le seul canal de paiement ouvert ici.
 */
async function payFullCommission(
    page: Page,
    livreurRegex: RegExp,
): Promise<void> {
    await page.goto('/backoffice/comptabilite/fiches/livreurs');

    const ficheLink = page.getByRole('link', { name: livreurRegex }).first();
    await ficheLink.waitFor({ state: 'visible', timeout: 20_000 });
    await ficheLink.click();

    await page.waitForURL(/\/comptabilite\/fiches\/[a-z0-9]+$/, {
        timeout: 20_000,
    });

    // waitForURL ne garantit que le changement d'URL, pas le remplacement du DOM
    // (transition Inertia) : sans cette attente, un combobox non scopé peut encore
    // matcher le filtre Agence de la page liste précédente (Fiches/Index.vue,
    // DataFilters) au lieu du "Mode de paiement" de cette page.
    await page
        .getByRole('heading', { name: /enregistrer un paiement/i })
        .waitFor({ state: 'visible', timeout: 15_000 });

    // Montant pré-rempli au solde restant de la fiche — seul le mode de paiement
    // (obligatoire, sans valeur par défaut) doit être renseigné avant de soumettre.
    // Scopé au <form> (comme les autres combobox de ce fichier de tests) : DataFilters,
    // utilisé sur la page liste précédente, ne rend jamais de <form>, donc ce scope
    // exclut aussi son filtre Agence par construction, indépendamment du timing.
    const modeCombobox = page.locator('form').getByRole('combobox').first();
    await modeCombobox.waitFor({ state: 'visible', timeout: 10_000 });
    await selectOptionFromCombobox(page, modeCombobox, /esp[eè]ces/i);

    const submitBtn = page.getByRole('button', {
        name: /enregistrer le paiement/i,
    });
    await submitBtn.waitFor({ state: 'visible', timeout: 10_000 });
    await submitBtn.click();

    // La fiche est intégralement soldée : le formulaire de paiement disparaît
    // (can_pay redevient false côté backend une fois montant_restant à 0).
    await submitBtn.waitFor({ state: 'hidden', timeout: 20_000 });
}
