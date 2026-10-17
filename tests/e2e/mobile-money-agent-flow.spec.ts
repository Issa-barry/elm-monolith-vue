import { expect, type Locator, type Page, test } from '@playwright/test';
import {
    configurerBaremes,
    configurerPartageVehicule,
    confirmAlertDialog,
} from './commission-v2-helpers';
import {
    creerCommande,
    demarrerEtValiderChargement,
} from './commissions/vente-workflow';
import { loginAsElmV2Demo, selectOptionFromCombobox } from './helpers';

/**
 * Mobile Money sur le compte de l'agent (ADR 0027, lots 1 à 3), parcours réel dans l'organisation
 * de démonstration V2 : compte Mobile Money de l'agence et compte de l'agent créés et validés depuis
 * Trésorerie → Supports ; une vente encaissée en Orange Money arrive sur le compte de l'AGENT, jamais
 * sur celui de l'agence ; l'agent transfère ensuite à l'agence (frais en sus), qui confirme la
 * réception dans Mouvements. Les règles fines sont verrouillées côté Feature
 * (EncaissementMobileMoneyCompteAgentTest, TransfertMobileMoneyAgentTest).
 *
 * Run: E2E_SKIP_GLOBAL_SETUP=1 npx playwright test tests/e2e/mobile-money-agent-flow.spec.ts --workers=1
 */

const SUPPORTS_URL = '/backoffice/comptabilite/tresorerie/supports';
const AGENCE = 'Siège V2 Demo';
const AGENT = 'Admin V2 Demo';
// Libellé automatique du compte de l'agence : unique, le serveur refusant un second Mobile Money
// sur le même compte comptable dans la même agence. Aucun numéro de téléphone (ADR 0030).
const LIBELLE_AGENCE = `Orange Money de ${AGENCE}`;
const FRAIS = 1_000;

test.setTimeout(300_000);

test.beforeEach(async ({ page }) => {
    await loginAsElmV2Demo(page);
});

function chiffres(texte: string | null): number {
    return Number((texte ?? '').replace(/\D/g, '') || '0');
}

async function ouvrirSupports(page: Page) {
    await page.goto(SUPPORTS_URL);
    await expect(
        page.getByRole('heading', { name: /supports de trésorerie/i }),
    ).toBeVisible({ timeout: 15_000 });
}

async function ouvrirCreation(page: Page): Promise<Locator> {
    await page.getByTestId('support-create-open').click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible({ timeout: 10_000 });
    return dialog;
}

async function valider(ligne: Locator) {
    await ligne.getByTestId('support-valider').click();
    await confirmAlertDialog(ligne.page(), /^valider$/i);
    await expect(ligne.getByTestId('support-statut')).toHaveText(/^actif$/i, {
        timeout: 10_000,
    });
}

const ligneSupport = (page: Page, texte: string) =>
    page.locator('tbody tr', { hasText: texte }).first();

test('Mobile Money : encaissement sur le compte de l’agent, puis transfert vers l’agence', async ({
    page,
}) => {
    // ── Compte Orange Money de l'agence ─────────────────────────────────────
    await ouvrirSupports(page);
    const agence = await ouvrirCreation(page);
    await agence.locator('#sup-site').selectOption({ label: AGENCE });
    await agence.locator('#sup-type').selectOption('mobile_money');
    await agence
        .locator('#sup-compte')
        .selectOption({ label: '561100 — Mobile Money — Orange Money' });
    await agence
        .locator('#sup-operateur')
        .selectOption({ label: 'Orange Money' });
    await expect(agence.locator('#sup-numero')).toHaveCount(0);
    await agence.getByRole('button', { name: /créer la caisse/i }).click();
    // Création + rechargement de la liste : lent sur un serveur de test déjà chargé.
    await expect(agence).toBeHidden({ timeout: 45_000 });
    const ligneAgence = ligneSupport(page, LIBELLE_AGENCE);
    await expect(ligneAgence).toBeVisible({ timeout: 10_000 });
    await valider(ligneAgence);

    // ── Compte Orange Money de l'agent ──────────────────────────────────────
    const agent = await ouvrirCreation(page);
    await agent.locator('#sup-site').selectOption({ label: AGENCE });
    await agent
        .locator('#sup-nature')
        .selectOption({ label: 'Dédié à un agent' });
    await agent
        .locator('#sup-type-dedie')
        .selectOption({ label: 'Compte Mobile Money' });
    await agent
        .locator('#sup-operateur-dedie')
        .selectOption({ label: 'Orange Money' });
    await expect(agent.locator('#sup-numero-dedie')).toHaveCount(0);
    await agent.locator('#sup-agent').selectOption({ label: AGENT });
    await agent.getByRole('button', { name: /créer le compte/i }).click();
    await expect(agent).toBeHidden({ timeout: 45_000 });
    const ligneAgent = ligneSupport(page, `Orange Money — ${AGENT}`);
    await expect(ligneAgent).toBeVisible({ timeout: 10_000 });
    await valider(ligneAgent);

    // ── Vente, puis encaissement en Orange Money ────────────────────────────
    await configurerBaremes(page);
    await configurerPartageVehicule(page);
    await creerCommande(page, /V2-DEMO-01/i);
    await demarrerEtValiderChargement(page);

    await page.goto('/backoffice/factures');
    await expect(page.locator('body')).toContainText(/factures de vente/i, {
        timeout: 20_000,
    });
    const facture = page
        .locator('tbody tr', { hasText: /véhicule v2 demo/i })
        .first();
    await expect(facture).toBeVisible({ timeout: 20_000 });
    await facture.locator('button').last().click();
    await page
        .getByRole('menuitem', { name: /encaisser/i })
        .first()
        .click();

    const paiement = page
        .locator('[role="dialog"]')
        .filter({ hasText: /encaisser/i });
    await expect(paiement).toBeVisible({ timeout: 10_000 });
    const montant = chiffres(
        await paiement.locator('input').first().inputValue(),
    );
    expect(montant).toBeGreaterThan(FRAIS);

    // Liste ouverte : une seule option Orange Money, le compte de l'agent — jamais celui de
    // l'agence (ADR 0027) — et aucun numéro de téléphone affiché (ADR 0030).
    await paiement.getByRole('combobox').last().click();
    const optionsOrange = page.getByRole('option', { name: /orange money/i });
    await expect(optionsOrange).toHaveCount(1, { timeout: 10_000 });
    await expect(optionsOrange).not.toContainText('N°');
    await page.keyboard.press('Escape');

    await selectOptionFromCombobox(
        page,
        paiement.getByRole('combobox').last(),
        /orange money/i,
    );
    await paiement
        .getByPlaceholder(/OM123456789|référence|transaction/i)
        .fill('OM-E2E-CLIENT-1');
    await paiement.getByRole('button', { name: /^confirmer$/i }).click();
    await expect(paiement).toBeHidden({ timeout: 15_000 });

    // ── L'argent est sur le compte de l'agent, pas sur celui de l'agence ────
    await ouvrirSupports(page);
    expect(
        chiffres(
            await ligneSupport(page, `Orange Money — ${AGENT}`)
                .getByTestId('support-solde')
                .textContent(),
        ),
    ).toBe(montant);
    await expect(
        ligneSupport(page, LIBELLE_AGENCE).getByTestId('support-solde'),
    ).toHaveText(/^0\b/);

    // ── Transfert vers l'agence : montant reçu + frais en sus ───────────────
    const recu = montant - FRAIS;
    await ligneSupport(page, `Orange Money — ${AGENT}`)
        .getByTestId('support-transferer')
        .click();
    const transfert = page.getByRole('dialog');
    await expect(transfert.getByTestId('transfert-form')).toBeVisible({
        timeout: 10_000,
    });
    await transfert.getByTestId('transfert-montant').fill(String(recu));
    await transfert.getByTestId('transfert-frais').fill(String(FRAIS));
    await transfert.locator('#transfert-reference').fill('OM-E2E-TRANSFERT-1');
    expect(
        chiffres(await transfert.getByTestId('transfert-sortie').textContent()),
    ).toBe(montant);
    await transfert.getByTestId('transfert-envoyer').click();
    await expect(transfert.getByTestId('transfert-form')).toBeHidden({
        timeout: 15_000,
    });
    await expect(
        ligneSupport(page, `Orange Money — ${AGENT}`).getByTestId(
            'support-en-cours-versement',
        ),
    ).toContainText(/en cours de transfert/i);

    // ── L'agence confirme la réception ──────────────────────────────────────
    await page.goto('/backoffice/comptabilite/tresorerie/mouvements');
    const mouvement = page
        .locator('tbody tr', { hasText: /transfert mobile money/i })
        .first();
    await expect(mouvement).toBeVisible({ timeout: 15_000 });
    await expect(mouvement.getByTestId('mouvement-frais')).toContainText(
        /frais/i,
    );
    await mouvement
        .getByRole('button', { name: /confirmer réception/i })
        .click();
    const reception = page.getByRole('dialog');
    await expect(reception.getByTestId('reception-versement')).toBeVisible({
        timeout: 10_000,
    });
    await reception.getByRole('button', { name: /^confirmer$/i }).click();
    await expect(mouvement.getByText(/^reçu$/i)).toBeVisible({
        timeout: 10_000,
    });

    // ── Soldes finaux : agent à 0, agence crédité du montant reçu ───────────
    await ouvrirSupports(page);
    await expect(
        ligneSupport(page, `Orange Money — ${AGENT}`).getByTestId(
            'support-solde',
        ),
    ).toHaveText(/^0\b/);
    const soldeAgence = chiffres(
        await ligneSupport(page, LIBELLE_AGENCE)
            .getByTestId('support-solde')
            .textContent(),
    );
    expect(soldeAgence).toBe(recu);
});
