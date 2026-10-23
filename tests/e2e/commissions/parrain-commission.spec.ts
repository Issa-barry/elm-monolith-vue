import { expect, test, type Locator, type Page } from '@playwright/test';
import { randomDigits, selectOptionFromCombobox } from '../helpers';

/**
 * Part du parrain dans l'enveloppe Livreur (ADR 0035) — parcours complet, de la vente au paiement
 * effectif de la fiche du parrain :
 *   ajout du parrain (onglet Parrain) → répartition 500 / 300 / 200 dans la modale Équipe →
 *   vente de 100 packs (enveloppe 100 000 GNF, part du parrain 20 000) → transfert entre sites sans
 *   part du parrain → période Parrains de la quinzaine validée d'office → fiche du parrain payée
 *   en partie (PaymentCard, espèces) → journal, comptes 622500 / 467210 / 467220, reste dû,
 *   Financement de l'agence → nouvelle vente = fiche complémentaire, fiche payée intacte →
 *   fiches livreurs inchangées.
 *
 * Contexte préparé par l'endpoint e2e-only /e2e/fixtures/parrain-commission, dans une
 * ORGANISATION DÉDIÉE (Testing\CommissionE2eFixturesController::parrainCommission) : barème
 * Livreur 1 000 GNF/pack en Vente et en Logistique, équipe chauffeur 600 / convoyeur 400, caisse
 * dédiée approvisionnée du payeur. Les opérations (vente, transfert) passent par
 * /e2e/fixtures/parrain-commission/{vehicule}/{type} : le parcours de vente a ses propres specs.
 */

interface Fixture {
    vehicule: { id: string; nom: string };
    categorie: { id: string; nom: string };
    variante_id: string;
    site: { id: string; nom: string };
}

interface Operation {
    reference: string;
    montant_total: number | null;
    parts: { type: string; montant: number }[];
}

interface Etat {
    fiches: {
        type: 'parrain' | 'livreur';
        periode: string;
        rang: number;
        montant_net: number;
        montant_paye: number;
        montant_restant: number;
        site_id: string;
    }[];
    soldes: Record<string, number>;
    libelles: Record<string, string>;
    pieces: Record<string, number>;
}

async function xsrf(page: Page): Promise<string> {
    const cookie = (await page.context().cookies()).find(
        (c) => c.name === 'XSRF-TOKEN',
    );
    if (!cookie) throw new Error('Cookie XSRF-TOKEN introuvable.');
    return decodeURIComponent(cookie.value);
}

async function post<T>(page: Page, url: string, data = {}): Promise<T> {
    const response = await page.request.post(url, {
        headers: {
            'X-XSRF-TOKEN': await xsrf(page),
            Accept: 'application/json',
        },
        data,
    });
    expect(response.ok(), await response.text()).toBeTruthy();
    return (await response.json()) as T;
}

async function lireEtat(page: Page, vehiculeId: string): Promise<Etat> {
    const response = await page.request.get(
        `/e2e/fixtures/parrain-commission/${vehiculeId}/etat`,
        { headers: { Accept: 'application/json' } },
    );
    expect(response.ok(), await response.text()).toBeTruthy();
    return (await response.json()) as Etat;
}

/** Saisie d'un InputNumber PrimeVue : le v-model n'est committé qu'au blur. */
async function saisirMontant(champ: Locator, montant: number): Promise<void> {
    const input = champ.locator('input');
    await input.click();
    await input.press('Control+A');
    await input.press('Backspace');
    await input.pressSequentially(String(montant));
    await input.blur();
}

const somme = (parts: Operation['parts'], type: string) =>
    parts.filter((p) => p.type === type).reduce((t, p) => t + p.montant, 0);

/** 15 000 tel que rendu en fr-FR (espace insécable ou fine comme séparateur). */
const gnf = (montant: number) =>
    new Intl.NumberFormat('fr-FR')
        .format(montant)
        .replace(/\s/g, '[\\s\\u202f\\u00a0]?');

test('parrain : de la vente au paiement partiel de sa fiche, sans toucher aux livreurs', async ({
    page,
}) => {
    test.setTimeout(240_000);
    // Organisation dédiée créée par la fixture, qui y connecte la session : on repart d'une
    // session vierge (le storageState par défaut connecte l'administrateur « elm »).
    await page.context().clearCookies();
    await page.goto('/login');
    const f = await post<Fixture>(page, '/e2e/fixtures/parrain-commission');
    const quinzaine = new Date().getDate() <= 15 ? 'P1' : 'P2';
    const nomParrain = `E2E Parrain ${randomDigits(6)}`;

    // ── 1. Ajout du parrain : part à 0, équipe inchangée ────────────────────────────
    await page.goto(`/backoffice/vehicules/${f.vehicule.id}?tab=parrain`);
    await page.getByTestId('ajouter-parrain-btn').click();
    await page
        .getByTestId('parrain-recherche-telephone-input')
        .fill(`6${randomDigits(8)}`);
    await page.getByTestId('parrain-rechercher-btn').click();
    await page.getByTestId('parrain-creer-personne-btn').click();
    await page.getByTestId('parrain-creation-nom-input').fill(nomParrain);
    await page.getByTestId('parrain-creer-et-associer-btn').click();
    await expect(page.getByTestId('parrain-nom')).toHaveText(nomParrain, {
        timeout: 15_000,
    });
    const remuneration = page.getByTestId('parrain-remuneration');
    await expect(remuneration).toContainText('Vente');
    // Véhicule Vente + Logistique : la distribution a sa propre ligne, jamais le transfert.
    await expect(remuneration).toContainText(/distribution/i);
    await expect(remuneration).not.toContainText(/transfert logistique/i);

    // ── 2. Répartition Vente 500 / 300 / 200 dans la modale Équipe ──────────────────
    await page.getByTestId('parrain-modifier-repartition-btn').click();
    const modale = page.getByRole('dialog');
    await modale.getByRole('button', { name: /suivant/i }).click();
    const carte = modale.getByTestId(`partage-categorie-${f.categorie.id}`);
    await expect(carte).toContainText(nomParrain);
    const membres = carte.locator('[data-testid^="partage-livreur-montant-"]');
    await expect(membres).toHaveCount(2);
    await saisirMontant(membres.nth(0), 500);
    await expect(carte.getByTestId('partage-livreur-etat')).toContainText(
        /reste à répartir/i,
    );
    await saisirMontant(membres.nth(1), 300);
    await saisirMontant(carte.getByTestId('partage-parrain-montant'), 200);
    await expect(carte.getByTestId('partage-livreur-etat')).toContainText(
        /équilibrée/i,
    );
    await modale.getByRole('button', { name: /suivant/i }).click();
    await expect(modale.getByTestId('recap-parrain')).toContainText(nomParrain);
    await modale.getByRole('button', { name: /enregistrer l.équipe/i }).click();
    await expect(modale).toBeHidden({ timeout: 15_000 });

    await page.goto(`/backoffice/vehicules/${f.vehicule.id}?tab=parrain`);
    await expect(
        page
            .getByTestId('parrain-remuneration')
            .locator('tr', { hasText: 'Vente' }),
    ).toContainText('200');

    // ── 3. Vente de 100 packs : la part du parrain est prise sur l'enveloppe ────────
    const vente = await post<Operation>(
        page,
        `/e2e/fixtures/parrain-commission/${f.vehicule.id}/vente`,
        { variante_id: f.variante_id, quantite: 100 },
    );
    expect(vente.montant_total).toBe(100_000);
    expect(somme(vente.parts, 'parrain')).toBe(20_000);
    expect(somme(vente.parts, 'livreur')).toBe(80_000);

    // ── 4. Transfert entre sites : aucune part pour le parrain ──────────────────────
    const transfert = await post<Operation>(
        page,
        `/e2e/fixtures/parrain-commission/${f.vehicule.id}/transfert`,
        { variante_id: f.variante_id, quantite: 50 },
    );
    expect(transfert.montant_total).toBe(50_000);
    expect(somme(transfert.parts, 'parrain')).toBe(0);
    expect(somme(transfert.parts, 'livreur')).toBe(50_000);

    // ── 5. Période Parrains de la quinzaine : calculée et validée d'office ─────────
    await page.goto('/backoffice/comptabilite/periodes');
    await page
        .getByRole('link', { name: /^parrains/i })
        .first()
        .click();
    await page.waitForURL(/\/comptabilite\/periodes\/[a-z0-9]+$/);
    await expect(
        page.getByText(new RegExp(`${quinzaine}-PAR`)).first(),
    ).toBeVisible();
    await expect(
        page.getByText('Validée', { exact: true }).first(),
    ).toBeVisible({
        timeout: 15_000,
    });

    const avantPaiement = await lireEtat(page, f.vehicule.id);
    const ficheParrain = avantPaiement.fiches.filter(
        (x) => x.type === 'parrain',
    );
    expect(ficheParrain).toHaveLength(1);
    expect(ficheParrain[0].periode).toContain(`${quinzaine}-PAR`);
    expect(ficheParrain[0].montant_net).toBe(20_000);
    expect(ficheParrain[0].site_id).toBe(f.site.id);
    expect(avantPaiement.pieces.fiche_parrain_validee).toBe(1);

    // ── 6. Fiche du parrain : paiement partiel de 5 000 GNF en espèces ──────────────
    await page.goto('/backoffice/comptabilite/fiches/parrains');
    await page.getByRole('link', { name: nomParrain }).first().click();
    await page.waitForURL(/\/comptabilite\/fiches\/[a-z0-9]+$/);
    await page.getByRole('button', { name: /^payer$/i }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toContainText(/payer une commission parrain/i);
    await selectOptionFromCombobox(
        page,
        dialog.getByRole('combobox').first(),
        /esp[eè]ces/i,
    );
    const montant = dialog.locator('.p-inputnumber input');
    await montant.click();
    await montant.press('Control+A');
    await montant.pressSequentially('5000');
    await montant.blur();
    await dialog.getByRole('button', { name: /^confirmer$/i }).click();
    await expect(dialog).toBeHidden({ timeout: 20_000 });
    await expect(
        page.getByText(new RegExp(`Reste\\s*:\\s*${gnf(15_000)}`)),
    ).toBeVisible({
        timeout: 10_000,
    });

    // ── 7. Comptabilité : charge 622500, dette 467210 soldée du paiement ───────────
    const apresPaiement = await lireEtat(page, f.vehicule.id);
    const parrainPaye = apresPaiement.fiches.find((x) => x.type === 'parrain');
    expect(parrainPaye).toMatchObject({
        montant_paye: 5_000,
        montant_restant: 15_000,
    });
    expect(apresPaiement.soldes['622500']).toBe(20_000);
    expect(apresPaiement.soldes['467210']).toBe(-15_000);
    expect(apresPaiement.soldes['467220'] ?? 0).toBe(0);
    expect(apresPaiement.pieces.paiement_parrain).toBe(1);
    for (const numero of ['622500', '467210', '467220']) {
        expect(apresPaiement.libelles[numero]).toMatch(/parrain/i);
    }

    await page.goto(
        '/backoffice/comptabilite/journal?evenement=paiement_parrain',
    );
    await expect(
        page.getByText(/paiement commission parrain/i).first(),
    ).toBeVisible();

    // ── 8. Financement de l'agence : reste dû du parrain dans sa quinzaine ─────────
    await page.goto(
        `/backoffice/comptabilite/tresorerie/financement/${f.site.id}`,
    );
    const section = page.locator('div.space-y-2').filter({
        has: page.getByRole('heading', {
            name: `Commissions parrains — ${quinzaine === 'P1' ? '1re' : '2e'} quinzaine`,
        }),
    });
    await expect(section.locator('tr', { hasText: nomParrain })).toContainText(
        new RegExp(gnf(15_000)),
    );

    // ── 9. Nouvelle vente : fiche complémentaire, fiche payée intacte ──────────────
    await post<Operation>(
        page,
        `/e2e/fixtures/parrain-commission/${f.vehicule.id}/vente`,
        { variante_id: f.variante_id, quantite: 10 },
    );
    await page.goto('/backoffice/comptabilite/periodes');
    await page
        .getByRole('link', { name: /^parrains/i })
        .first()
        .click();
    await page.waitForURL(/\/comptabilite\/periodes\/[a-z0-9]+$/);

    const final = await lireEtat(page, f.vehicule.id);
    const fichesParrain = final.fiches.filter((x) => x.type === 'parrain');
    expect(fichesParrain).toHaveLength(2);
    expect(fichesParrain[0]).toMatchObject({
        rang: 1,
        montant_net: 20_000,
        montant_paye: 5_000,
    });
    expect(fichesParrain[1]).toMatchObject({
        rang: 2,
        montant_net: 2_000,
        montant_paye: 0,
    });

    // ── 10. Livreurs : uniquement leur part (ventes 80 000 + 8 000, transfert 50 000) ─
    await page.goto('/backoffice/comptabilite/periodes');
    await page
        .getByRole('link', { name: /^livreurs/i })
        .first()
        .click();
    await page.waitForURL(/\/comptabilite\/periodes\/[a-z0-9]+$/);
    const livreurs = (await lireEtat(page, f.vehicule.id)).fiches.filter(
        (x) => x.type === 'livreur',
    );
    expect(livreurs).toHaveLength(2);
    expect(livreurs.reduce((t, x) => t + x.montant_net, 0)).toBe(138_000);
    expect(livreurs.every((x) => x.montant_paye === 0)).toBe(true);
});
