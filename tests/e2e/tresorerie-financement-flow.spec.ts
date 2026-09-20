/**
 * tresorerie-financement-flow.spec.ts
 * Parcours de bout en bout du chantier "Financement des agences" (remplace l'ancien
 * "Besoin de trésorerie") : configuration d'un support de trésorerie par site, création
 * d'un mouvement de fonds siège -> agence, envoi, réception, puis vérification que l'écran
 * de financement reflète le nouveau disponible. Le calcul exact (obligations, disponible,
 * à financer) est couvert en profondeur côté Feature (FinancementAgenceServiceTest,
 * MouvementFondsServiceTest) — ce fichier vérifie le câblage UI de bout en bout.
 *
 * Utilise l'organisation "elm" par défaut (login()) — Matoto y est déjà le site de type
 * "siege" (cf. capture d'écran du produit, is_siege_principal auto-assigné par Site::boot()).
 *
 * Run: npx playwright test tests/e2e/tresorerie-financement-flow.spec.ts --workers=1
 */
import { expect, type Locator, type Page, test } from '@playwright/test';
import { confirmAlertDialog } from './commission-v2-helpers';
import { login } from './helpers';

test.setTimeout(120_000);

const SUPPORTS_URL = '/backoffice/comptabilite/tresorerie/supports';

test.beforeEach(async ({ page }) => {
    await login(page);
});

/**
 * Valide un support en brouillon (bouton « Valider » de la ligne, puis confirmation) : tant qu'il
 * n'est pas validé, un support est inutilisable (encaissements, mouvements, soldes d'ouverture).
 */
async function validerSupport(ligne: Locator) {
    await ligne.getByTestId('support-valider').click();
    await confirmAlertDialog(ligne.page(), /^valider$/i);
    await expect(ligne.getByTestId('support-statut')).toHaveText(/^actif$/i, {
        timeout: 10_000,
    });
}

/** Ouvre le menu ⋮ d'une ligne et choisit une de ses entrées (menu rendu hors du tableau). */
async function actionDuMenu(ligne: Locator, entree: RegExp) {
    await ligne.getByTestId('support-actions').click();
    await ligne.page().getByRole('menuitem', { name: entree }).click();
}

/** Ouvre le dialogue « Créer une caisse » (bouton « Nouvelle caisse ») et le renvoie. */
async function ouvrirCreation(page: Page) {
    await page.getByTestId('support-create-open').click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible({ timeout: 10_000 });
    return dialog;
}

test('la page Financement des agences se charge avec ses cartes et son tableau', async ({
    page,
}) => {
    await page.goto('/backoffice/comptabilite/tresorerie/financement');

    await expect(
        page.getByRole('heading', { name: /financement des agences/i }),
    ).toBeVisible({ timeout: 15_000 });

    // .first() : "Total à régler" apparaît à la fois dans la carte KPI (<p>) et
    // dans l'en-tête de colonne du tableau (<th>) — collision volontaire de libellé,
    // pas une ambiguïté de test ; on vérifie juste la présence du texte.
    await expect(page.getByText(/total à régler/i).first()).toBeVisible();
    await expect(page.getByText(/disponible dans les agences/i)).toBeVisible();
    await expect(page.getByText(/à financer par le siège/i)).toBeVisible();

    // Sélecteur d'échéance compact (1re quinzaine / Fin de mois / Mois complet).
    await expect(
        page.getByRole('button', { name: /1re quinzaine/i }),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: /mois complet/i }),
    ).toBeVisible();
});

/**
 * Parcours complet demandé par la revue du 2026-08-22 : création d'un support
 * SANS libellé (génération automatique "{Type} de {Site}"), validation du support
 * (brouillon → actif), saisie formatée du montant du solde d'ouverture, puis
 * validation de ce solde — reproduit exactement le bug signalé (404 sur "Valider"
 * causé par l'envoi de l'ID du support au lieu de l'ID du solde d'ouverture) pour
 * prouver qu'il ne se reproduit plus.
 */
test('support sans libellé, solde formaté, validation sans 404', async ({
    page,
}) => {
    await page.goto(SUPPORTS_URL);

    await expect(
        page.getByRole('heading', { name: /supports de trésorerie/i }),
    ).toBeVisible({ timeout: 15_000 });

    // ── 1-2. Création sans libellé → libellé automatique ────────────────────
    const creation = await ouvrirCreation(page);
    await creation.locator('#sup-site').selectOption({ label: 'Sonfonia' });
    await creation.locator('#sup-type').selectOption('caisse');
    await creation
        .locator('#sup-compte')
        .selectOption({ label: '571000 — Caisse' });
    // Libellé volontairement laissé vide.
    await creation.getByRole('button', { name: /créer la caisse/i }).click();

    const ligne = page.locator('tbody tr', { hasText: 'Sonfonia' }).first();
    await expect(ligne).toBeVisible({ timeout: 10_000 });
    await expect(ligne).toContainText(/caisse de sonfonia/i);

    // Un support est créé en brouillon : inutilisable (ni solde d'ouverture ni mouvement) tant
    // qu'il n'est pas validé — le menu ⋮ ne propose alors que « Modifier ».
    await expect(ligne.getByTestId('support-statut')).toHaveText(
        /^brouillon$/i,
    );
    await ligne.getByTestId('support-actions').click();
    await expect(
        page.getByRole('menuitem', { name: /saisir le solde d'ouverture/i }),
    ).toHaveCount(0);
    await page.keyboard.press('Escape');

    await validerSupport(ligne);

    // ── 3-5. Solde d'ouverture : saisie et affichage formaté ────────────────
    await actionDuMenu(ligne, /saisir le solde d'ouverture/i);

    const dialog = page.getByRole('dialog');
    const montantInput = dialog.locator('input[inputmode="numeric"]');
    await montantInput.fill('20000000');
    // toLocaleString('fr-FR') sépare les milliers par une espace fine insécable
    // (U+202F), pas une espace normale — cf. MontantNormalizer côté serveur qui
    // doit d'ailleurs tolérer ce caractère.
    await expect(montantInput).toHaveValue('20\u{202F}000\u{202F}000');

    // ── 6. Enregistrement ────────────────────────────────────────────────────
    await dialog.getByRole('button', { name: /^enregistrer$/i }).click();
    await expect(page.getByText(/solde d'ouverture enregistré/i)).toBeVisible({
        timeout: 10_000,
    });

    // ── 7-9. Validation : pas de 404, alerte levée, toast en haut ───────────
    // Tant que le solde d'ouverture n'est pas validé, la liste le signale en ambre — et elle
    // n'en dit plus rien une fois validé (le détail reste dans « Modifier le support »).
    await expect(ligne.getByTestId('support-alerte-solde')).toContainText(
        /solde d'ouverture à valider/i,
        { timeout: 10_000 },
    );

    const responsePromise = page.waitForResponse(
        (r) =>
            r.url().includes('/soldes-ouverture/') &&
            r.url().includes('/valider'),
    );
    await actionDuMenu(ligne, /valider le solde d'ouverture/i);
    const response = await responsePromise;
    expect(
        response.status(),
        'la validation ne doit jamais renvoyer 404',
    ).not.toBe(404);

    await expect(ligne.getByTestId('support-alerte-solde')).toHaveCount(0, {
        timeout: 10_000,
    });
    await expect(ligne.getByTestId('support-statut')).toHaveText(/^actif$/i);

    // Toast en haut à droite (group="top", cf. useFlashToast) — jamais en bas.
    const toast = page.locator('.p-toast', { hasText: /validé/i }).first();
    await expect(toast).toBeVisible({ timeout: 10_000 });
    const box = await toast.boundingBox();
    expect(
        box?.y ?? 9999,
        "le toast doit apparaître en haut de l'écran",
    ).toBeLessThan(300);
});

/**
 * Reproduit le cas signalé le 2026-08-22 : un support "Sonfonia" avait été créé
 * avec le type Caisse mais le compte 561300 (Mobile Money Djomy), car le
 * dropdown "Compte comptable" ne filtrait rien selon le type choisi.
 */
test('le compte comptable proposé est filtré selon le type de support sélectionné', async ({
    page,
}) => {
    await page.goto(SUPPORTS_URL);
    await expect(
        page.getByRole('heading', { name: /supports de trésorerie/i }),
    ).toBeVisible({ timeout: 15_000 });

    const creation = await ouvrirCreation(page);
    const typeSelect = creation.locator('#sup-type');
    const compteSelect = creation.locator('#sup-compte');

    // Type par défaut = Caisse : un seul compte compatible (571000) → présélectionné.
    await expect(compteSelect.locator('option:checked')).toHaveText(
        '571000 — Caisse',
    );

    // Type = Banque : un seul compte compatible (521000) → présélectionné.
    await typeSelect.selectOption('banque');
    await expect(compteSelect.locator('option:checked')).toHaveText(
        '521000 — Banque',
    );

    // Type = Mobile Money : les comptes Caisse/Banque disparaissent de la liste.
    await typeSelect.selectOption('mobile_money');
    const optionTexts = await compteSelect.locator('option').allTextContents();
    expect(optionTexts.some((t) => t.includes('571000'))).toBe(false);
    expect(optionTexts.some((t) => t.includes('521000'))).toBe(false);
    expect(optionTexts.some((t) => t.includes('561'))).toBe(true);
});

test('créer, envoyer et recevoir un mouvement de fonds', async ({ page }) => {
    // Prérequis : au moins deux supports de trésorerie (créés par le test précédent ou déjà
    // présents) — ce test crée les siens pour rester indépendant si lancé seul.
    await page.goto(SUPPORTS_URL);
    await expect(
        page.getByRole('heading', { name: /supports de trésorerie/i }),
    ).toBeVisible({ timeout: 15_000 });

    async function creerSupportSiNecessaire(site: string, libelle: string) {
        if (!(await page.getByText(libelle, { exact: false }).count())) {
            const creation = await ouvrirCreation(page);
            await creation.locator('#sup-site').selectOption({ label: site });
            await creation.locator('#sup-type').selectOption('caisse');
            await creation
                .locator('#sup-compte')
                .selectOption({ label: '571000 — Caisse' });
            await creation.locator('#sup-libelle').fill(libelle);
            await creation
                .getByRole('button', { name: /créer la caisse/i })
                .click();
            await expect(page.getByText(libelle)).toBeVisible({
                timeout: 10_000,
            });
        }

        // Créé en brouillon (ou resté en brouillon d'un essai précédent) : il doit être validé
        // pour être proposé dans un mouvement de fonds.
        const ligne = page.locator('tbody tr', { hasText: libelle }).first();
        if (await ligne.getByTestId('support-valider').count()) {
            await validerSupport(ligne);
        }
    }

    await creerSupportSiNecessaire('Matoto', 'Caisse Matoto E2E 2');
    await creerSupportSiNecessaire('Kouria', 'Caisse Kouria E2E 2');

    // ── Création du mouvement ──────────────────────────────────────────────
    await page.goto('/backoffice/comptabilite/tresorerie/mouvements/create');
    await expect(
        page.getByRole('heading', { name: /nouveau mouvement de fonds/i }),
    ).toBeVisible({ timeout: 15_000 });

    await page.locator('select').nth(0).selectOption({ label: 'Matoto' }); // site origine
    await page
        .locator('select')
        .nth(1)
        .selectOption({ label: 'Caisse Matoto E2E 2' }); // support origine
    // Le support de destination n'est plus demandé à la création : le
    // destinataire le choisit au moment de « Confirmer réception ».
    await page.locator('select').nth(2).selectOption({ label: 'Kouria' }); // site destination
    await page.locator('input[inputmode="numeric"]').fill('150000');
    await page.getByRole('button', { name: /créer le brouillon/i }).click();

    await expect(page).toHaveURL(/mouvements$/, { timeout: 10_000 });

    const row = page.locator('tbody tr', { hasText: 'Kouria' }).first();
    await expect(row).toBeVisible({ timeout: 10_000 });

    // ── Envoi ──────────────────────────────────────────────────────────────
    await row.getByRole('button', { name: /^envoyer$/i }).click();
    // Regex ancrée : la ligne affiche aussi « Envoyé par … le … » sous la référence.
    await expect(row.getByText(/^envoyé$/i)).toBeVisible({ timeout: 10_000 });

    // ── Réception : le destinataire choisit le support qui a reçu les fonds ─
    await row.getByRole('button', { name: /confirmer réception/i }).click();
    const receptionDialog = page.getByRole('dialog');
    await expect(receptionDialog).toBeVisible({ timeout: 10_000 });
    await receptionDialog
        .locator('select')
        .selectOption({ label: 'Caisse Kouria E2E 2' });
    await receptionDialog.getByRole('button', { name: /^confirmer$/i }).click();
    await expect(row.getByText(/^reçu$/i)).toBeVisible({ timeout: 10_000 });
});

/**
 * Caisse dédiée à un agent (Trésorerie > Supports, décision du 2026-09-19) : création depuis
 * le dialogue, sans saisir de compte comptable (créé automatiquement), démarrage à 0 GNF,
 * responsable affiché, et agent retiré de la liste dès qu'il a une caisse active sur l'agence
 * (une seule caisse dédiée active par agent et par site). Les règles fines (rattachement au
 * site, unicité, sous-compte, Financement) sont verrouillées côté Feature.
 */
test('créer une caisse dédiée à un agent : solde à 0, responsable affiché, agent retiré de la liste', async ({
    page,
}) => {
    await page.goto(SUPPORTS_URL);
    await expect(
        page.getByRole('heading', { name: /supports de trésorerie/i }),
    ).toBeVisible({ timeout: 15_000 });

    const creation = await ouvrirCreation(page);
    await creation.locator('#sup-site').selectOption({ label: 'Matoto' });
    await creation
        .locator('#sup-nature')
        .selectOption({ label: 'Caisse dédiée à un agent' });

    // Le premier agent proposé (l'option 0 est le placeholder).
    const agentSelect = creation.locator('#sup-agent');
    const nomAgent = (
        (await agentSelect.locator('option').nth(1).textContent()) ?? ''
    ).trim();
    expect(nomAgent, 'au moins un agent rattaché à Matoto').not.toBe('');
    await agentSelect.selectOption({ label: nomAgent });

    // Aucun choix de compte comptable pour une caisse dédiée : il est créé automatiquement.
    await expect(creation.locator('#sup-compte')).toHaveCount(0);

    await creation.getByRole('button', { name: /créer la caisse/i }).click();

    const ligne = page
        .locator('tbody tr', { hasText: `Caisse ${nomAgent}` })
        .first();
    await expect(ligne).toBeVisible({ timeout: 10_000 });
    await expect(ligne).toContainText(nomAgent);
    await expect(ligne.getByTestId('support-solde')).toHaveText(/^0\b/);
    // Créée en brouillon : elle ne reçoit aucun encaissement avant sa validation, et l'agent
    // reste proposé (seule une caisse ACTIVE l'écarte de la liste).
    await expect(ligne.getByTestId('support-statut')).toHaveText(
        /^brouillon$/i,
    );
    await validerSupport(ligne);
    // Pas de solde d'ouverture pour une caisse dédiée : elle démarre à 0.
    await ligne.getByTestId('support-actions').click();
    await expect(
        page.getByRole('menuitem', { name: /solde d'ouverture/i }),
    ).toHaveCount(0);
    await page.keyboard.press('Escape');

    // L'agent a maintenant une caisse active à Matoto : il n'est plus proposé.
    const reouverture = await ouvrirCreation(page);
    await reouverture.locator('#sup-site').selectOption({ label: 'Matoto' });
    await reouverture
        .locator('#sup-nature')
        .selectOption({ label: 'Caisse dédiée à un agent' });
    await expect(
        reouverture.locator('#sup-agent option', { hasText: nomAgent }),
    ).toHaveCount(0);
});

test('le filtre Nature de l’URL n’affiche que les caisses dédiées', async ({
    page,
}) => {
    await page.goto(`${SUPPORTS_URL}?nature=dediee`);
    await expect(
        page.getByRole('heading', { name: /supports de trésorerie/i }),
    ).toBeVisible({ timeout: 15_000 });

    // Les supports d'agence (« Responsable : Agence ») disparaissent de la liste.
    const lignes = page.getByTestId('support-row');
    await expect(lignes.first()).toBeVisible({ timeout: 10_000 });
    await expect(page.getByRole('cell', { name: /^Agence$/ })).toHaveCount(0);
});

test('responsive : la page Financement reste utilisable sur mobile', async ({
    page,
}) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/backoffice/comptabilite/tresorerie/financement');

    await expect(
        page.getByRole('heading', { name: /financement des agences/i }),
    ).toBeVisible({ timeout: 15_000 });

    // Le tableau doit scroller horizontalement plutôt que casser la mise en page globale.
    const body = page.locator('body');
    const bodyBox = await body.boundingBox();
    expect(bodyBox?.width).toBeLessThanOrEqual(390 + 1);
});
