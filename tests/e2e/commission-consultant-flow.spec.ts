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
import { loginAsElmV2Demo } from './helpers';

test.setTimeout(120_000);

const CATEGORIE_NOM = "Sachets d'eau V2 Demo";

test.beforeEach(async ({ page }) => {
    await loginAsElmV2Demo(page);
});

test('définir un barème "Consultant" par catégorie dans Paramètres > Commissions', async ({
    page,
}) => {
    await page.goto('/settings/commissions');
    await expect(
        page.getByRole('heading', { name: /^commissions$/i }),
    ).toBeVisible({ timeout: 15_000 });

    // Colonne "Consultant" présente à côté de Propriétaire/Livraison/Site — jamais un onglet
    // séparé, jamais un nom de prestataire codé en dur ("Fello Consulting").
    await expect(
        page.getByRole('columnheader', { name: /^consultant$/i }),
    ).toBeVisible();
    await expect(page.getByText(/fello/i)).toHaveCount(0);

    const row = page
        .locator('tbody tr', { hasText: new RegExp(CATEGORIE_NOM, 'i') })
        .first();
    await expect(row).toBeVisible({ timeout: 15_000 });

    // Ordre des colonnes cible : Propriétaire, Livraison, Site, Consultant (cf.
    // CommissionRegleController).
    const consultantCell = row.getByRole('button').nth(3);
    await consultantCell.click();

    const dialog = page.getByRole('dialog', { name: /consultant/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });
    await dialog.locator('#cr-montant').fill('500');
    await dialog.getByRole('button', { name: /enregistrer/i }).click();
    await expect(dialog).toBeHidden({ timeout: 10_000 });

    // Toast de succès en haut (jamais en bas), cf. mission §5/§10.
    await expect(page.getByText(/barème enregistré/i)).toBeVisible({
        timeout: 10_000,
    });
    await expect(consultantCell).toContainText(/500/);
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
    await page.keyboard.press('Escape');

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
        .filter({ has: commissionsButton });
    await commissionsItem.getByRole('link', { name: /^consultants$/i }).click();

    await expect(page).toHaveURL(/\/comptabilite\/commissions\/consultants$/, {
        timeout: 15_000,
    });

    await expect(accountingButton).toHaveAttribute('data-active', 'true');
    await expect(commissionsButton).toHaveAttribute('data-active', 'true');
});
