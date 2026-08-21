/**
 * commission-site-flow.spec.ts
 * Commission attribuée directement au site métier de l'opération — parcours UI de bout en bout :
 * définition d'un barème "Site" dans Paramètres > Commissions, rendu de l'écran comptable dédié
 * (DataFilters/StatusDot/exports, cf. CLAUDE.md), et navigation latérale. Le calcul exact des
 * montants et la génération transactionnelle sont couverts en profondeur côté Feature
 * (CommissionEnveloppeGeneratorSiteTest, CommissionSiteTest) — ce fichier vérifie le câblage UI,
 * pas l'arithmétique (même découpage que commission-v2-full-chain.spec.ts).
 *
 * Run: npx playwright test tests/e2e/commission-site-flow.spec.ts --workers=1
 */
import { expect, test } from '@playwright/test';
import { login } from './helpers';

test.setTimeout(120_000);

test.beforeEach(async ({ page }) => {
    await login(page);
});

test('définir un barème "Site" par catégorie dans Paramètres > Commissions', async ({
    page,
}) => {
    await page.goto('/backoffice/settings/commissions');
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

    const globalRow = page
        .locator('tr')
        .filter({ hasText: /toutes catégories/i })
        .first();
    const siteCell = globalRow.locator('td', { hasText: /définir|gnf/i }).last();
    await siteCell.getByRole('button').click();

    await expect(
        page.locator('[role="dialog"]').filter({ hasText: /^site/i }),
    ).toBeVisible({ timeout: 10_000 });

    await page.locator('#cr-montant').fill('1000');
    await page.getByRole('button', { name: /enregistrer/i }).click();

    // Toast de succès en haut (jamais en bas), cf. mission §10.
    await expect(page.getByText(/barème enregistré/i)).toBeVisible({
        timeout: 10_000,
    });
});

test('l\'écran Comptabilité > Commission sites se charge avec ses filtres et ses cartes de synthèse', async ({
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
    await expect(
        page.getByRole('button', { name: /^filtres/i }),
    ).toBeVisible();

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
