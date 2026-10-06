/**
 * commission-consultant-flow.spec.ts
 * Commission versée à un prestataire consultant désigné par l'organisation — parcours UI de
 * bout en bout : définition d'un barème "Consultant" dans Paramètres > Commissions, rendu de
 * l'écran comptable dédié (DataFilters/StatusDot/exports, cf. CLAUDE.md), et navigation latérale.
 * Le calcul exact des montants et la génération transactionnelle sont couverts en profondeur côté
 * Feature (CommissionEnveloppeGeneratorConsultantTest, CommissionConsultantTest) — ce fichier
 * vérifie le câblage UI, pas l'arithmétique (même découpage que commission-site-flow.spec.ts).
 *
 * Utilise l'organisation dédiée "Eau La Maman V2 Demo" (loginAsElmV2Demo) — seule organisation
 * du projet avec le processus vente V2 activé, cf. ElmV2DemoSeeder et commission-v2-full-chain.spec.ts.
 * E2E_SKIP_GLOBAL_SETUP=1 évite le préchargement Legacy (transferts/commissions logistique) du
 * global-setup, inutile et non lié à cette organisation.
 *
 * Run: E2E_SKIP_GLOBAL_SETUP=1 npx playwright test tests/e2e/commission-consultant-flow.spec.ts --workers=1
 */
import { expect, test } from '@playwright/test';
import { configurerBareme, montantPattern } from './commissions/helpers';
import { closeFilterDrawerIfOpen, loginAsElmV2Demo } from './helpers';

test.setTimeout(120_000);

const CATEGORIE_NOM = "Sachets d'eau V2 Demo";
const CONSULTANT_LABEL = 'Consultant V2 Demo';

test.beforeEach(async ({ page }) => {
    await loginAsElmV2Demo(page);
});

test('définir un barème "Consultant" par catégorie dans Paramètres > Commissions', async ({
    page,
}) => {
    await configurerBareme(page, {
        categorieNom: CATEGORIE_NOM,
        montants: { consultant: 500 },
        consultantLabel: CONSULTANT_LABEL,
    });

    await page.goto('/settings/commissions');
    await expect(
        page.getByRole('heading', { name: /^commissions$/i }),
    ).toBeVisible({ timeout: 15_000 });

    // Colonne "Consultant" présente à côté de Propriétaire/Livreur/Site — jamais un onglet
    // séparé, jamais un nom de prestataire codé en dur ("Fello Consulting").
    await expect(
        page.getByText('Consultant', { exact: true }).first(),
    ).toBeVisible();
    await expect(page.getByText(/fello/i)).toHaveCount(0);

    const row = page
        .locator('[data-testid^="commission-row-"]', { hasText: CATEGORIE_NOM })
        .first();
    await expect(row).toBeVisible({ timeout: 15_000 });
    await expect(row).toContainText(new RegExp(montantPattern(500)));
    await expect(row).toContainText(CONSULTANT_LABEL);
});

test("l'écran Comptabilité > Commission consultants se charge avec ses filtres et ses cartes de synthèse", async ({
    page,
}) => {
    await page.goto('/backoffice/comptabilite/commissions/consultants');

    await expect(
        page.getByRole('heading', { name: /commissions des consultants/i }),
    ).toBeVisible({ timeout: 15_000 });

    // Max 4 cartes de synthèse (cf. mission), jamais plus.
    const cards = page.getByTestId('commission-summary-cards').locator('> div');
    await expect(cards).toHaveCount(4);

    // Filtres standards, DataFilters.vue — jamais de <select> fait maison (cf. CLAUDE.md).
    const filtresButton = page.getByRole('button', { name: /^filtres/i });
    await expect(filtresButton).toBeVisible();

    // Aucun sélecteur Agence dans le drawer : le consultant est désigné au niveau organisation,
    // pas d'un site (cf. mission §5, CommissionIndexLayout hide-agence-selector).
    await filtresButton.click();
    await expect(page.getByTestId('filters-drawer')).toBeVisible();
    await expect(page.getByTestId('agency-filter')).toHaveCount(0);
    await closeFilterDrawerIfOpen(page);

    // Export unique en dropdown (Excel + PDF), jamais deux boutons séparés.
    await page.getByRole('button', { name: /^exporter$/i }).click();
    await expect(
        page.getByRole('menuitem', { name: /exporter en excel/i }),
    ).toBeVisible();
    await expect(
        page.getByRole('menuitem', { name: /exporter en pdf/i }),
    ).toBeVisible();
    await page.keyboard.press('Escape');

    // Aucune colonne "Agence"/"Site" résiduelle — le bénéficiaire affiché est le prestataire.
    await expect(
        page.getByRole('columnheader', { name: /^(agence|site)$/i }),
    ).toHaveCount(0);
});

test('la navigation latérale regroupe "Consultants" sous Comptabilité > Commissions', async ({
    page,
}) => {
    await page.goto('/backoffice/dashboard');

    const accountingButton = page.getByRole('button', {
        name: /^comptabilité$/i,
    });
    await accountingButton.click();

    const accountingItem = page
        .locator('[data-sidebar="menu-item"]')
        .filter({ has: accountingButton });

    const commissionsButton = accountingItem.getByRole('button', {
        name: /^commissions$/i,
    });
    await commissionsButton.click();
    await expect(commissionsButton).toHaveAttribute('aria-expanded', 'true');

    const commissionsItem = accountingItem
        .locator('[data-sidebar="menu-sub-item"]')
        .filter({ has: page.getByRole('button', { name: /^commissions$/i }) });
    await commissionsItem.getByRole('link', { name: /^consultants$/i }).click();

    await expect(page).toHaveURL(/\/comptabilite\/commissions\/consultants$/, {
        timeout: 15_000,
    });

    await expect(accountingButton).toHaveAttribute('data-active', 'true');
    await expect(commissionsButton).toHaveAttribute('data-active', 'true');
});
