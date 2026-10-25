import { expect, test, type Locator, type Page } from '@playwright/test';
import { randomDigits, selectOptionFromCombobox } from '../helpers';

/**
 * Part du parrain dans l'enveloppe Livreur (ADR 0035) — parcours complet, de la vente au paiement
 * effectif de la fiche du parrain :
 *   ajout du parrain (onglet Parrain) → part 200 saisie en place (deux livreurs : partage « À faire »)
 *   → répartition 500 / 300 dans la modale Équipe →
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

/** Cellule de la liste Financement des agences : ligne de l'agence × colonne nommée. */
async function celluleFinancement(
    page: Page,
    agence: string,
    colonne: string,
): Promise<Locator> {
    await expect(
        page.locator('tbody tr', { hasText: agence }).first(),
    ).toBeVisible();
    const entetes = (await page.locator('thead th').allInnerTexts()).map((t) =>
        t.trim(),
    );
    const index = entetes.indexOf(colonne);
    expect(
        index,
        `Colonne « ${colonne} » absente : ${entetes.join(' | ')}`,
    ).toBeGreaterThan(-1);
    return page
        .locator('tbody tr', { hasText: agence })
        .first()
        .locator('td')
        .nth(index);
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

    // PARRAIN-024 : pas d'étape supplémentaire — l'onglet Parrain passe en saisie de sa part.
    await expect(page.getByTestId('part-parrain-edition')).toBeVisible({
        timeout: 15_000,
    });
    await page.getByTestId('part-parrain-annuler-btn').click();
    await expect(page.getByTestId('part-parrain-edition')).toBeHidden();
    await expect(page.getByTestId('parrain-nom')).toHaveText(nomParrain);
    const remuneration = page.getByTestId('parrain-remuneration');
    await expect(remuneration).toContainText('Vente');
    // Véhicule Vente + Logistique : la distribution a sa propre ligne, jamais le transfert.
    await expect(remuneration).toContainText(/distribution/i);
    await expect(remuneration).not.toContainText(/transfert logistique/i);
    await expect(page.getByTestId('parrain-part-a-definir')).toBeVisible();
    // Véhicule Logistique : les transferts entre sites sont signalés, jamais rémunérés.
    await expect(
        page.getByTestId('parrain-ligne-transfert-exclu'),
    ).toContainText(/jamais rémunéré/i);

    // Rappels hors de l'onglet : onglet Équipe et liste (filtre « Part du parrain à définir »).
    await page.goto(`/backoffice/vehicules/${f.vehicule.id}?tab=equipe`);
    await expect(page.getByTestId('equipe-rappel-part-parrain')).toContainText(
        nomParrain,
    );
    // Le parrain apparaît hors équipe, sa part comprise dans le total réparti (= barème).
    await expect(page.getByTestId('equipe-ligne-parrain')).toContainText(
        nomParrain,
    );
    await expect(page.getByTestId('equipe-ligne-parrain')).toContainText(
        /à répartir/i,
    );
    await expect(page.getByTestId('equipe-total-reparti')).toContainText(
        /barème/i,
    );
    await page.goto('/backoffice/vehicules?parrain_part=a_definir');
    await expect(
        page.getByTestId(`part-parrain-${f.vehicule.id}`),
    ).toContainText(/à définir/i);

    // ── 2. Part du parrain saisie en place : Vente 200, Distribution 0 confirmée ────
    await page.goto(`/backoffice/vehicules/${f.vehicule.id}?tab=parrain`);
    await expect(
        page.getByTestId('parrain-definir-part-onglet-btn'),
    ).toHaveText(/modifier les parts/i);
    await page.getByTestId('parrain-definir-part-onglet-btn').click();
    const edition = page.getByTestId('part-parrain-edition');
    // Une ligne par partage du parrain : Vente et Distribution (jamais le transfert).
    await expect(
        edition.getByTestId('part-parrain-input-distribution_client'),
    ).toBeVisible();
    await expect(edition).toContainText(/jamais rémunéré/i);
    await saisirMontant(edition.getByTestId('part-parrain-input-vente'), 200);
    // Deux livreurs : leurs montants ne changent pas, répartition à refaire dans Équipe.
    await expect(
        edition.getByTestId('part-parrain-a-repartir-vente'),
    ).toBeVisible();
    await expect(
        edition.getByTestId('part-parrain-avertissement-equipe'),
    ).toContainText(/ventes/i);
    // PARRAIN-022 : la Distribution reste à 0 GNF — confirmation explicite exigée pour elle seule.
    const confirmation = edition.getByTestId('part-parrain-confirmer-nulle');
    await expect(confirmation).toContainText(/distribution/i);
    await expect(confirmation).not.toContainText(/ventes/i);
    await expect(
        page.getByTestId('part-parrain-enregistrer-btn'),
    ).toBeDisabled();
    await confirmation.getByRole('checkbox').click();
    await page.getByTestId('part-parrain-enregistrer-btn').click();
    await expect(edition).toBeHidden({ timeout: 15_000 });

    await expect(page.getByTestId('parrain-part-a-definir')).toBeHidden();
    await expect(
        page
            .getByTestId('parrain-remuneration')
            .locator('tr', { hasText: 'Vente' }),
    ).toContainText('200');

    // ── 2 bis. Onglet Équipe : Vente « À faire », répartie 500 / 300 ────────────────
    await page.goto(`/backoffice/vehicules/${f.vehicule.id}?tab=equipe`);
    const statutVente = page.getByTestId(
        `statut-partage-vente-${f.categorie.id}`,
    );
    await expect(statutVente).toContainText(/à faire/i);
    await expect(
        page.getByTestId(
            `statut-partage-distribution_client-${f.categorie.id}`,
        ),
    ).toContainText(/fait/i);
    await page
        .getByRole('button', { name: /gérer l'équipe/i })
        .first()
        .click();
    const modale = page
        .locator('[role="dialog"]')
        .filter({ hasText: /équipe/i });
    await modale.getByRole('button', { name: /suivant/i }).click();
    const carteEquipe = modale
        .getByTestId(`partage-categorie-${f.categorie.id}`)
        .first();
    // La part saisie dans l'onglet Parrain est reprise, modifiable aussi ici.
    await expect(
        carteEquipe.getByTestId('partage-parrain-montant').locator('input'),
    ).toHaveValue('200');
    const membresEquipe = carteEquipe.locator(
        '[data-testid^="partage-livreur-montant-"]',
    );
    await expect(membresEquipe).toHaveCount(2);
    await saisirMontant(membresEquipe.nth(0), 500);
    await saisirMontant(membresEquipe.nth(1), 300);
    await expect(carteEquipe.getByTestId('partage-livreur-etat')).toContainText(
        /équilibrée/i,
    );
    await modale.getByRole('button', { name: /suivant/i }).click();
    await modale.getByRole('button', { name: /enregistrer l'équipe/i }).click();
    await expect(modale).toBeHidden({ timeout: 15_000 });
    await expect(statutVente).toContainText(/fait/i);

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

    // Liste du financement : colonnes Parrains P1 / P2 (mois complet), puis seule la colonne
    // de la quinzaine courante quand on filtre sur son échéance.
    const autre = quinzaine === 'P1' ? 'P2' : 'P1';
    await page.goto('/backoffice/comptabilite/tresorerie/financement');
    await expect(
        await celluleFinancement(page, f.site.nom, `Parrains ${quinzaine}`),
    ).toContainText(new RegExp(gnf(15_000)));
    await expect(
        await celluleFinancement(page, f.site.nom, `Parrains ${autre}`),
    ).not.toContainText(new RegExp(gnf(15_000)));
    await page.screenshot({
        path: test.info().outputPath('financement-mois-complet.png'),
        fullPage: true,
    });

    await page.goto(
        `/backoffice/comptabilite/tresorerie/financement?echeance=${quinzaine.toLowerCase()}`,
    );
    await expect(
        await celluleFinancement(page, f.site.nom, `Parrains ${quinzaine}`),
    ).toContainText(new RegExp(gnf(15_000)));
    await expect(
        page.locator('thead th', { hasText: `Parrains ${autre}` }),
    ).toHaveCount(0);
    await page.screenshot({
        path: test.info().outputPath(`financement-${quinzaine}.png`),
        fullPage: true,
    });

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

test('un seul livreur et un parrain : la part saisie calcule l’autre, dans les deux sens', async ({
    page,
}) => {
    test.setTimeout(180_000);
    await page.context().clearCookies();
    await page.goto('/login');
    const f = await post<Fixture>(page, '/e2e/fixtures/parrain-commission', {
        livreurs: 1,
    });
    const nomParrain = `E2E Parrain ${randomDigits(6)}`;

    await page.goto(`/backoffice/vehicules/${f.vehicule.id}?tab=parrain`);
    await page.getByTestId('ajouter-parrain-btn').click();
    await page
        .getByTestId('parrain-recherche-telephone-input')
        .fill(`6${randomDigits(8)}`);
    await page.getByTestId('parrain-rechercher-btn').click();
    await page.getByTestId('parrain-creer-personne-btn').click();
    await page.getByTestId('parrain-creation-nom-input').fill(nomParrain);
    await page.getByTestId('parrain-creer-et-associer-btn').click();

    // Après l'association, l'onglet Parrain passe directement en saisie (PARRAIN-024).
    const edition = page.getByTestId('part-parrain-edition');
    const champParrainVente = edition.getByTestId('part-parrain-input-vente');
    const livreurCalcule = edition.getByTestId('part-parrain-livreur-vente');
    await expect(champParrainVente).toBeVisible({ timeout: 15_000 });

    // Un seul livreur : il reçoit le reste du barème (1 000 − 200).
    await saisirMontant(champParrainVente, 200);
    await expect(livreurCalcule).toContainText(/800/);

    // Au-delà du barème : ramenée au barème, avec un message explicite.
    await saisirMontant(champParrainVente, 1500);
    await expect(
        edition.getByTestId('part-parrain-alerte-vente'),
    ).toContainText(/ne peut pas dépasser.*ramenée/i);
    await expect(livreurCalcule).toContainText(/:\s*0\s*GNF/);
    await saisirMontant(champParrainVente, 100);
    await expect(livreurCalcule).toContainText(/900/);

    // La Distribution reste à 0 : la confirmation explicite reste exigée.
    await expect(
        page.getByTestId('part-parrain-enregistrer-btn'),
    ).toBeDisabled();
    await edition
        .getByTestId('part-parrain-confirmer-nulle')
        .getByRole('checkbox')
        .click();
    await page.getByTestId('part-parrain-enregistrer-btn').click();
    await expect(edition).toBeHidden({ timeout: 15_000 });

    await expect(
        page
            .getByTestId('parrain-remuneration')
            .locator('tr', { hasText: 'Vente' }),
    ).toContainText('100');

    // Un seul livreur : le partage reste « Fait », rien à refaire dans Équipe.
    await page.goto(`/backoffice/vehicules/${f.vehicule.id}?tab=equipe`);
    await expect(
        page.getByTestId(`statut-partage-vente-${f.categorie.id}`),
    ).toContainText(/fait/i);
    await expect(
        page.getByTestId(`statut-partage-vente-${f.categorie.id}`),
    ).not.toContainText(/à faire/i);

    // Même calcul dans la modale Équipe, dès la frappe (sans sortir du champ).
    await page
        .getByRole('button', { name: /gérer l'équipe/i })
        .first()
        .click();
    const modale = page
        .locator('[role="dialog"]')
        .filter({ hasText: /équipe/i });
    await modale.getByRole('button', { name: /suivant/i }).click();
    const carteEquipe = modale
        .getByTestId(`partage-categorie-${f.categorie.id}`)
        .first();
    await expect(carteEquipe.getByTestId('partage-calcul-auto')).toBeVisible();
    const champLivreur = carteEquipe
        .locator('[data-testid^="partage-livreur-montant-"]')
        .locator('input');
    const champParrain = carteEquipe
        .getByTestId('partage-parrain-montant')
        .locator('input');
    const etatEquipe = carteEquipe.getByTestId('partage-livreur-etat');
    const taper = async (champ: Locator, valeur: string) => {
        await champ.click();
        await champ.press('Control+A');
        await champ.press('Backspace');
        await champ.pressSequentially(valeur);
    };

    await taper(champParrain, '250');
    await expect(champLivreur).toHaveValue('750');
    await expect(etatEquipe).toContainText(/équilibrée/i);
    await carteEquipe.screenshot({
        path: test.info().outputPath('equipe-parrain-saisi.png'),
    });

    await taper(champLivreur, '400');
    await expect(champParrain).toHaveValue('600');
    await expect(etatEquipe).toContainText(/équilibrée/i);
    await carteEquipe.screenshot({
        path: test.info().outputPath('equipe-livreur-saisi.png'),
    });

    // Limites : tout le barème au parrain, puis tout au livreur (part du parrain 0).
    await taper(champParrain, '1000');
    await expect(champLivreur).toHaveValue('');
    await expect(etatEquipe).toContainText(/équilibrée/i);
    await taper(champLivreur, '1000');
    await expect(champParrain).toHaveValue('0');
    await expect(etatEquipe).toContainText(/équilibrée/i);
    // Part du parrain calculée à 0 : la confirmation explicite reste demandée, non cochée.
    const confirmationZero = modale.getByTestId(
        `confirmer-part-parrain-nulle-${f.categorie.id}`,
    );
    await expect(confirmationZero).toBeVisible();
    await expect(confirmationZero.getByRole('checkbox')).not.toBeChecked();
    await page.keyboard.press('Escape');
});

/** Ajoute un nouveau parrain depuis l'onglet Parrain (téléphone inconnu → création). */
async function ajouterParrain(page: Page, nom: string): Promise<void> {
    await page
        .getByTestId('parrain-recherche-telephone-input')
        .fill(`6${randomDigits(8)}`);
    await page.getByTestId('parrain-rechercher-btn').click();
    await page.getByTestId('parrain-creer-personne-btn').click();
    await page.getByTestId('parrain-creation-nom-input').fill(nom);
    await page.getByTestId('parrain-creer-et-associer-btn').click();
}

test('onglets Parrain et Équipe : mêmes montants, sans rechargement de la page', async ({
    page,
}) => {
    test.setTimeout(180_000);
    await page.context().clearCookies();
    await page.goto('/login');
    const f = await post<Fixture>(page, '/e2e/fixtures/parrain-commission', {
        livreurs: 1,
    });

    await page.goto(`/backoffice/vehicules/${f.vehicule.id}?tab=parrain`);
    await page.getByTestId('ajouter-parrain-btn').click();
    await ajouterParrain(page, `E2E Parrain ${randomDigits(6)}`);

    // Onglet Parrain : 200 GNF/pack.
    const edition = page.getByTestId('part-parrain-edition');
    await expect(edition).toBeVisible({ timeout: 15_000 });
    await saisirMontant(edition.getByTestId('part-parrain-input-vente'), 200);
    await edition
        .getByTestId('part-parrain-confirmer-nulle')
        .getByRole('checkbox')
        .click();
    await page.getByTestId('part-parrain-enregistrer-btn').click();
    await expect(edition).toBeHidden({ timeout: 15_000 });

    // Onglet Équipe par clic (aucun rechargement) : la modale reprend 200, on passe à 150.
    await page.getByTestId('equipe-tab-btn').click();
    await page
        .getByRole('button', { name: /gérer l'équipe/i })
        .first()
        .click();
    const modale = page
        .locator('[role="dialog"]')
        .filter({ hasText: /équipe/i });
    await modale.getByRole('button', { name: /suivant/i }).click();
    const carte = modale
        .getByTestId(`partage-categorie-${f.categorie.id}`)
        .first();
    const champParrain = carte.getByTestId('partage-parrain-montant');
    await expect(champParrain.locator('input')).toHaveValue('200');
    await saisirMontant(champParrain, 150);
    await expect(
        carte
            .locator('[data-testid^="partage-livreur-montant-"]')
            .locator('input'),
    ).toHaveValue('850');
    await modale.getByRole('button', { name: /suivant/i }).click();
    await modale.getByRole('button', { name: /enregistrer l'équipe/i }).click();
    await expect(modale).toBeHidden({ timeout: 15_000 });

    // Retour à l'onglet Parrain par clic : 150, sans rechargement.
    await page.getByTestId('parrain-tab-btn').click();
    await expect(
        page
            .getByTestId('parrain-remuneration')
            .locator('tr', { hasText: 'Vente' }),
    ).toContainText('150');
});

test('changer de parrain : conséquences confirmées, le nouveau démarre à 0 GNF', async ({
    page,
}) => {
    test.setTimeout(180_000);
    await page.context().clearCookies();
    await page.goto('/login');
    const f = await post<Fixture>(page, '/e2e/fixtures/parrain-commission');
    const ancien = `E2E Ancien ${randomDigits(6)}`;
    const nouveau = `E2E Nouveau ${randomDigits(6)}`;

    await page.goto(`/backoffice/vehicules/${f.vehicule.id}?tab=parrain`);
    await page.getByTestId('ajouter-parrain-btn').click();
    await ajouterParrain(page, ancien);
    const edition = page.getByTestId('part-parrain-edition');
    await expect(edition).toBeVisible({ timeout: 15_000 });
    await saisirMontant(edition.getByTestId('part-parrain-input-vente'), 200);
    await edition
        .getByTestId('part-parrain-confirmer-nulle')
        .getByRole('checkbox')
        .click();
    await page.getByTestId('part-parrain-enregistrer-btn').click();
    await expect(edition).toBeHidden({ timeout: 15_000 });

    // Équipe : 500 / 300 + parrain 200 — partage complet (« Fait »).
    await page.getByTestId('equipe-tab-btn').click();
    await page
        .getByRole('button', { name: /gérer l'équipe/i })
        .first()
        .click();
    const modale = page
        .locator('[role="dialog"]')
        .filter({ hasText: /équipe/i });
    await modale.getByRole('button', { name: /suivant/i }).click();
    const membres = modale
        .getByTestId(`partage-categorie-${f.categorie.id}`)
        .first()
        .locator('[data-testid^="partage-livreur-montant-"]');
    await saisirMontant(membres.nth(0), 500);
    await saisirMontant(membres.nth(1), 300);
    await modale.getByRole('button', { name: /suivant/i }).click();
    await modale.getByRole('button', { name: /enregistrer l'équipe/i }).click();
    await expect(modale).toBeHidden({ timeout: 15_000 });
    const statutVente = page.getByTestId(
        `statut-partage-vente-${f.categorie.id}`,
    );
    await expect(statutVente).toContainText(/fait/i);
    await expect(statutVente).not.toContainText(/à faire/i);

    // Changement : aucune répartition à saisir, seulement les conséquences à confirmer.
    await page.getByTestId('parrain-tab-btn').click();
    await page.getByTestId('changer-parrain-btn').click();
    await page
        .getByTestId('parrain-recherche-telephone-input')
        .fill(`6${randomDigits(8)}`);
    await page.getByTestId('parrain-rechercher-btn').click();
    await page.getByTestId('parrain-creer-personne-btn').click();
    await page.getByTestId('parrain-creation-nom-input').fill(nouveau);
    await page.getByTestId('parrain-creer-et-associer-btn').click();
    const consequences = page.getByTestId('parrain-consequences');
    await expect(consequences).toContainText(/0 GNF à partir d'aujourd'hui/i);
    await expect(consequences).toContainText(
        /conserve toutes ses commissions/i,
    );
    await expect(page.getByTestId('parrain-consequence-vente')).toContainText(
        /à répartir entre les 2 livreurs/i,
    );
    await expect(
        page.locator('[data-testid^="partage-livreur-montant-"]'),
    ).toHaveCount(0);
    await page.getByTestId('parrain-changement-confirmer-btn').click();

    // Le nouveau parrain : saisie de sa part ouverte, à 0 GNF.
    await expect(edition).toBeVisible({ timeout: 15_000 });
    await expect(
        edition.getByTestId('part-parrain-input-vente').locator('input'),
    ).toHaveValue('0');
    await page.getByTestId('part-parrain-annuler-btn').click();
    await expect(page.getByTestId('parrain-nom')).toHaveText(nouveau);

    // Deux livreurs : la part de l'ancien n'est pas redistribuée d'office — Vente « À faire ».
    await page.getByTestId('equipe-tab-btn').click();
    await expect(statutVente).toContainText(/à faire/i);
});
