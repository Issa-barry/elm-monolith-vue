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
import { expect, test } from '@playwright/test';
import { login } from './helpers';

test.setTimeout(120_000);

test.beforeEach(async ({ page }) => {
    await login(page);
});

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
 * SANS libellé (génération automatique "{Type} de {Site}"), saisie formatée
 * du montant du solde d'ouverture, puis validation — reproduit exactement le
 * bug signalé (404 sur "Valider" causé par l'envoi de l'ID du support au lieu
 * de l'ID du solde d'ouverture) pour prouver qu'il ne se reproduit plus.
 */
test('support sans libellé, solde formaté, validation sans 404', async ({
    page,
}) => {
    await page.goto('/backoffice/comptabilite/tresorerie/supports');

    await expect(
        page.getByRole('heading', { name: /supports de trésorerie/i }),
    ).toBeVisible({ timeout: 15_000 });

    // ── 1-2. Création sans libellé → libellé automatique ────────────────────
    const form = page.locator('form').first();
    await form.locator('select').nth(0).selectOption({ label: 'Sonfonia' });
    await form.locator('select').nth(1).selectOption('caisse');
    await form
        .locator('select')
        .nth(2)
        .selectOption({ label: '571000 — Caisse' });
    // Libellé volontairement laissé vide.
    await form.getByRole('button', { name: /ajouter/i }).click();

    const ligne = page.locator('tbody tr', { hasText: 'Sonfonia' }).first();
    await expect(ligne).toBeVisible({ timeout: 10_000 });
    await expect(ligne).toContainText(/caisse de sonfonia/i);

    // ── 3-5. Solde d'ouverture : saisie et affichage formaté ────────────────
    await ligne
        .getByRole('button', { name: /saisir le solde d'ouverture/i })
        .click();

    const dialog = page.getByText("Solde d'ouverture").locator('..');
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

    // ── 7-9. Validation : pas de 404, statut "Validé", toast en haut ────────
    await expect(ligne.getByRole('button', { name: /^valider$/i })).toBeVisible(
        {
            timeout: 10_000,
        },
    );

    const responsePromise = page.waitForResponse(
        (r) =>
            r.url().includes('/soldes-ouverture/') &&
            r.url().includes('/valider'),
    );
    await ligne.getByRole('button', { name: /^valider$/i }).click();
    const response = await responsePromise;
    expect(
        response.status(),
        'la validation ne doit jamais renvoyer 404',
    ).not.toBe(404);

    await expect(ligne.getByText(/^validé$/i)).toBeVisible({ timeout: 10_000 });

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
    await page.goto('/backoffice/comptabilite/tresorerie/supports');
    await expect(
        page.getByRole('heading', { name: /supports de trésorerie/i }),
    ).toBeVisible({ timeout: 15_000 });

    const form = page.locator('form').first();
    const typeSelect = form.locator('select').nth(1);
    const compteSelect = form.locator('select').nth(2);

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
    await page.goto('/backoffice/comptabilite/tresorerie/supports');
    await expect(
        page.getByRole('heading', { name: /supports de trésorerie/i }),
    ).toBeVisible({ timeout: 15_000 });

    async function creerSupportSiNecessaire(site: string, libelle: string) {
        if (await page.getByText(libelle, { exact: false }).count()) return;
        const form = page.locator('form').first();
        await form.locator('select').nth(0).selectOption({ label: site });
        await form.locator('select').nth(1).selectOption('caisse');
        await form
            .locator('select')
            .nth(2)
            .selectOption({ label: '571000 — Caisse' });
        await form.locator('input[type="text"]').fill(libelle);
        await form.getByRole('button', { name: /ajouter/i }).click();
        await expect(page.getByText(libelle)).toBeVisible({ timeout: 10_000 });
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
    await page.locator('select').nth(2).selectOption({ label: 'Kouria' }); // site destination
    await page
        .locator('select')
        .nth(3)
        .selectOption({ label: 'Caisse Kouria E2E 2' }); // support destination
    await page.locator('input[type="number"]').fill('150000');
    await page.getByRole('button', { name: /créer le brouillon/i }).click();

    await expect(page).toHaveURL(/mouvements$/, { timeout: 10_000 });

    const row = page.locator('tbody tr', { hasText: 'Kouria' }).first();
    await expect(row).toBeVisible({ timeout: 10_000 });

    // ── Envoi ──────────────────────────────────────────────────────────────
    await row.getByRole('button', { name: /^envoyer$/i }).click();
    await expect(row.getByText(/envoyé/i)).toBeVisible({ timeout: 10_000 });

    // ── Réception ──────────────────────────────────────────────────────────
    await row.getByRole('button', { name: /confirmer réception/i }).click();
    await expect(row.getByText(/^reçu$/i)).toBeVisible({ timeout: 10_000 });
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
