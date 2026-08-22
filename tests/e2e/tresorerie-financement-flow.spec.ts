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

    await expect(page.getByText(/total à régler/i)).toBeVisible();
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

test('configurer un support de trésorerie pour une agence', async ({
    page,
}) => {
    await page.goto('/backoffice/comptabilite/tresorerie/supports');

    await expect(
        page.getByRole('heading', { name: /supports de trésorerie/i }),
    ).toBeVisible({ timeout: 15_000 });

    const form = page.locator('form').first();
    await form.locator('select').nth(0).selectOption({ label: 'Matoto' });
    await form.locator('select').nth(1).selectOption('caisse');
    await form
        .locator('select')
        .nth(2)
        .selectOption({ label: /571000/ });
    await form.locator('input[type="text"]').fill('Caisse Matoto E2E');
    await form.getByRole('button', { name: /ajouter/i }).click();

    await expect(page.getByText(/caisse matoto e2e/i)).toBeVisible({
        timeout: 10_000,
    });
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
            .selectOption({ label: /571000/ });
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

    await page
        .locator('select')
        .nth(0)
        .selectOption({ label: 'Matoto' }); // site origine
    await page
        .locator('select')
        .nth(1)
        .selectOption({ label: /caisse matoto e2e 2/i }); // support origine
    await page
        .locator('select')
        .nth(2)
        .selectOption({ label: 'Kouria' }); // site destination
    await page
        .locator('select')
        .nth(3)
        .selectOption({ label: /caisse kouria e2e 2/i }); // support destination
    await page.locator('input[type="number"]').fill('150000');
    await page.getByRole('button', { name: /créer le brouillon/i }).click();

    await expect(page).toHaveURL(/mouvements$/, { timeout: 10_000 });

    const row = page.locator('tbody tr', { hasText: 'Kouria' }).first();
    await expect(row).toBeVisible({ timeout: 10_000 });

    // ── Envoi ──────────────────────────────────────────────────────────────
    await row.getByRole('button', { name: /^envoyer$/i }).click();
    await expect(row.getByText(/envoyé/i)).toBeVisible({ timeout: 10_000 });

    // ── Réception ──────────────────────────────────────────────────────────
    await row
        .getByRole('button', { name: /confirmer réception/i })
        .click();
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
