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

    const browser = await chromium.launch();
    const context = await browser.newContext({ baseURL });
    const page = await context.newPage();

    try {
        await login(page);
        await context.storageState({ path: path.join(authDir, 'user.json') });

        // elm-2 (80 packs): Aissatou 11 200 GNF + Thierno 4 800 GNF — laissés impayés
        const ref001 = await createTransfertAndGenerateCommission(page, /elm-2/i);

        // elm-1 (120 packs): Boubacar 24 000 GNF — sera entièrement payé ci-dessous
        const ref002 = await createTransfertAndGenerateCommission(page, /elm-1/i);

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
 * et génère la commission (montant_par_pack = 200 GNF, pré-rempli par défaut).
 * Retourne la référence du transfert (ex: "TL-A1B2C3D4").
 */
async function createTransfertAndGenerateCommission(
    page: Page,
    vehicleName: RegExp,
): Promise<string> {
    await page.goto('/logistique/creer');
    await page.locator('#logistique-form').waitFor({ state: 'visible', timeout: 20_000 });

    const form = page.locator('#logistique-form');

    const siteSourceCombobox = form
        .locator('[data-testid="site-source-field"]')
        .getByRole('combobox');
    if ((await siteSourceCombobox.count()) > 0) {
        await selectOptionFromCombobox(page, siteSourceCombobox, /lansanaya|lambagny|dabompa/i);
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

    // Extract reference displayed as "N° transfert : TL-XXXXXXXX"
    const refElement = page.locator(':text("N° transfert")').first();
    await refElement.waitFor({ state: 'visible', timeout: 10_000 });
    const refText = (await refElement.textContent()) ?? '';
    const refMatch = refText.match(/TL-[A-Z0-9]+/i);
    if (!refMatch) {
        throw new Error(`Cannot extract transfert reference from page text: "${refText}"`);
    }
    const reference = refMatch[0].toUpperCase();

    // ── Brouillon → Chargement ────────────────────────────────────────────────
    const btnDemarrer = page.getByRole('button', { name: /démarrer le chargement/i });
    await btnDemarrer.waitFor({ state: 'visible', timeout: 15_000 });
    await btnDemarrer.click();

    // ── Chargement → Transit (via dialog) ────────────────────────────────────
    const btnValiderChargement = page.getByRole('button', { name: /valider le chargement/i });
    await btnValiderChargement.waitFor({ state: 'visible', timeout: 20_000 });
    await btnValiderChargement.click();

    const btnLivraison = page.getByRole('button', { name: /valider et partir en livraison/i });
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
    const btnGenerer = page.getByRole('button', { name: /générer commission/i });
    await btnGenerer.waitFor({ state: 'visible', timeout: 20_000 });
    await btnGenerer.click();

    // Étape 1 (review) : confirmer la réception
    const btnOuiGenerer = page.getByRole('button', { name: /oui, générer la commission/i });
    await btnOuiGenerer.waitFor({ state: 'visible', timeout: 10_000 });
    await btnOuiGenerer.click();

    // Étape 2 (montant) : montant_par_pack pré-rempli à 200 GNF — confirmer directement
    const btnConfirmer = page.getByRole('button', { name: /confirmer et générer/i });
    await btnConfirmer.waitFor({ state: 'visible', timeout: 10_000 });
    await btnConfirmer.click();

    // Attendre que la commission soit générée (le bouton disparaît)
    await btnGenerer.waitFor({ state: 'hidden', timeout: 20_000 });

    return reference;
}

/**
 * Navigue vers /logistique/commissions et paie intégralement la commission
 * du livreur correspondant au regex (le dialog est pré-rempli avec le solde
 * total — pas besoin de saisir un montant).
 */
async function payFullCommission(page: Page, livreurRegex: RegExp): Promise<void> {
    await page.goto('/logistique/commissions');

    const row = page.locator('tbody tr', { hasText: livreurRegex }).first();
    await row.waitFor({ state: 'visible', timeout: 20_000 });
    await row.locator('button').last().click();

    const payerItem = page.getByRole('menuitem', { name: /payer/i }).first();
    await payerItem.waitFor({ state: 'visible', timeout: 5_000 });
    await payerItem.click();

    const dialog = page.locator('[role="dialog"]').first();
    await dialog.waitFor({ state: 'visible', timeout: 10_000 });

    const confirmerBtn = dialog.getByRole('button', { name: /confirmer le paiement/i });
    await confirmerBtn.waitFor({ state: 'visible', timeout: 5_000 });
    await confirmerBtn.click();

    await dialog.waitFor({ state: 'hidden', timeout: 20_000 });
}
