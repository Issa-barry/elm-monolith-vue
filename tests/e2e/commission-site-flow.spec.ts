/**
 * commission-site-flow.spec.ts
 * Commission attribuée directement au site métier de l'opération — parcours UI de bout en bout :
 * définition d'un barème "Site" dans Paramètres > Commissions, rendu de l'écran comptable dédié
 * (DataFilters/StatusDot/exports, cf. CLAUDE.md), et navigation latérale. Le calcul exact des
 * montants et la génération transactionnelle sont couverts en profondeur côté Feature
 * (CommissionEnveloppeGeneratorSiteTest, CommissionSiteTest) — ce fichier vérifie le câblage UI,
 * pas l'arithmétique (même découpage que commission-v2-full-chain.spec.ts).
 *
 * Utilise l'organisation dédiée "Eau La Maman V2 Demo" (loginAsElmV2Demo) — seule organisation
 * du projet avec le processus vente V2 activé, cf. ElmV2DemoSeeder et commission-v2-full-chain.spec.ts.
 * E2E_SKIP_GLOBAL_SETUP=1 évite le préchargement Legacy (transferts/commissions logistique) du
 * global-setup, inutile et non lié à cette organisation.
 *
 * Run: E2E_SKIP_GLOBAL_SETUP=1 npx playwright test tests/e2e/commission-site-flow.spec.ts --workers=1
 */
import { expect, test } from '@playwright/test';
import { loginAsElmV2Demo } from './helpers';

test.setTimeout(120_000);

const CATEGORIE_NOM = "Sachets d'eau V2 Demo";

test.beforeEach(async ({ page }) => {
    await loginAsElmV2Demo(page);
});

test('définir un barème "Site" par catégorie dans Paramètres > Commissions', async ({
    page,
}) => {
    await page.goto('/settings/commissions');
    await expect(
        page.getByRole('heading', { name: /^commissions$/i }),
    ).toBeVisible({ timeout: 15_000 });

    // Colonne "Site" présente à côté de Propriétaire/Livraison — jamais un onglet séparé, jamais
    // de mention résiduelle de "gérants dépôt".
    await expect(
        page.getByRole('columnheader', { name: /^site$/i }),
    ).toBeVisible();
    await expect(
        page.getByRole('columnheader', { name: /gérants? dépôt/i }),
    ).toHaveCount(0);

    const row = page
        .locator('tbody tr', { hasText: new RegExp(CATEGORIE_NOM, 'i') })
        .first();
    await expect(row).toBeVisible({ timeout: 15_000 });

    // Ordre des colonnes cible : Propriétaire, Livraison, Site (cf. CommissionRegleController).
    const siteCell = row.getByRole('button').nth(2);
    await siteCell.click();

    const dialog = page.getByRole('dialog', { name: /site/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });
    await dialog.locator('#cr-montant').fill('1000');
    await dialog.getByRole('button', { name: /enregistrer/i }).click();
    await expect(dialog).toBeHidden({ timeout: 10_000 });

    // Toast de succès en haut (jamais en bas), cf. mission §10.
    await expect(page.getByText(/barème enregistré/i)).toBeVisible({
        timeout: 10_000,
    });
    await expect(siteCell).toContainText(/1.?000/);
});

test("l'écran Comptabilité > Commission sites se charge avec ses filtres et ses cartes de synthèse", async ({
    page,
}) => {
    await page.goto('/backoffice/comptabilite/commissions/sites');

    await expect(
        page.getByRole('heading', { name: /commissions des sites/i }),
    ).toBeVisible({ timeout: 15_000 });

    // Max 4 cartes de synthèse (cf. mission), jamais plus.
    const cards = page.getByTestId('commission-summary-cards').locator('> div');
    await expect(cards).toHaveCount(4);

    // Filtres standards, DataFilters.vue — jamais de <select> fait maison (cf. CLAUDE.md).
    await expect(page.getByRole('button', { name: /^filtres/i })).toBeVisible();

    // Export unique en dropdown (Excel + PDF), jamais deux boutons séparés.
    await page.getByRole('button', { name: /^exporter$/i }).click();
    await expect(
        page.getByRole('menuitem', { name: /exporter en excel/i }),
    ).toBeVisible();
    await expect(
        page.getByRole('menuitem', { name: /exporter en pdf/i }),
    ).toBeVisible();
    await page.keyboard.press('Escape');

    // Aucune colonne "Gérant"/employé résiduelle — le bénéficiaire affiché est le site.
    await expect(
        page.getByRole('columnheader', { name: /^gérant$/i }),
    ).toHaveCount(0);
});

test('la navigation latérale expose "Commission sites" sous Comptabilité', async ({
    page,
}) => {
    await page.goto('/backoffice/dashboard');
    await page.getByRole('button', { name: /^comptabilité$/i }).click();
    await page.getByRole('link', { name: /^commission sites$/i }).click();

    await expect(page).toHaveURL(/\/comptabilite\/commissions\/sites$/, {
        timeout: 15_000,
    });
});
