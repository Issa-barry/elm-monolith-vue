import { expect, test } from '@playwright/test';
import { login } from './helpers';

test.setTimeout(120_000);

/**
 * Vérifie que les 3 pages détail commission (Vente / Logistique / Propriétaire)
 * partagent désormais la même UI : 5 cartes résumé, mêmes tabs, même dialog de
 * paiement. La logistique s'appuie sur les transferts créés via UI dans
 * global-setup.ts (aucun seeder). Vente et Propriétaire dépendent de
 * CommissionsSeeder, désactivé dans DatabaseSeeder — ces deux tests prennent
 * donc la première ligne disponible et se "skip" proprement si la liste est
 * vide plutôt que d'échouer sur des données qui n'existent pas.
 *
 * Les tests logistique ciblent Thierno SALL (elm-2, 4 800 GNF impayé) plutôt
 * qu'Aissatou BALDÉ car logistique-commission-flow.spec.ts paie intégralement
 * Aissatou — Thierno reste impayé quelle que soit l'ordre d'exécution parallèle.
 */

const SUMMARY_LABELS = [
    'Brut cumulé',
    'Net à payer',
    'Déjà payé',
    'Reste à payer',
];

const TAB_LABELS = ['Informations', 'Dépenses', 'Paiements', 'Historique'];

async function assertSummaryCardsAndTabs(
    page: import('@playwright/test').Page,
    fraisLabel: string,
) {
    for (const label of [...SUMMARY_LABELS, fraisLabel]) {
        await expect(
            page.getByText(label, { exact: true }).first(),
        ).toBeVisible({ timeout: 15_000 });
    }

    for (const label of TAB_LABELS) {
        await expect(
            page.getByRole('button', { name: label, exact: false }).first(),
        ).toBeVisible();
    }
}

test('détail Commission logistique — 5 cartes, tabs, dialog paiement', async ({
    page,
}) => {
    await login(page);
    await page.goto('/comptabilite/commissions/logistique');

    const row = page
        .locator('tbody tr', { hasText: /Thierno\s+SALL/i })
        .first();
    await expect(row).toBeVisible({ timeout: 20_000 });
    await row.click();

    await expect(page).toHaveURL(
        /\/comptabilite\/commissions\/logistique\/livreurs\/[a-z0-9]+$/,
        { timeout: 20_000 },
    );

    await assertSummaryCardsAndTabs(page, 'Dépenses');

    // Onglet Dépenses désormais disponible pour la logistique.
    await page.getByRole('button', { name: 'Dépenses', exact: false }).click();
    await expect(page.locator('body')).toBeVisible();

    // Bouton Payer présent (solde impayé) et ouvre le dialog partagé.
    const payButton = page.getByRole('button', { name: /^payer/i });
    await expect(payButton).toBeVisible({ timeout: 10_000 });
    await payButton.click();

    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /Thierno/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });
    await expect(dialog.getByText(/solde à payer/i)).toBeVisible();
    await page.keyboard.press('Escape');
});

test('détail Commission vente — 5 cartes et tabs identiques', async ({
    page,
}) => {
    await login(page);
    await page.goto('/comptabilite/commissions/vente');

    const row = page.locator('tbody tr:has(td)').first();
    const hasRow = await row.isVisible({ timeout: 10_000 }).catch(() => false);
    test.skip(
        !hasRow,
        'Aucun bénéficiaire seedé pour Commission vente dans cet environnement (CommissionsSeeder désactivé).',
    );

    await row.click();

    await expect(page).toHaveURL(
        /\/comptabilite\/commissions\/vente\/livreurs\/[a-z0-9]+$/,
        { timeout: 20_000 },
    );

    await assertSummaryCardsAndTabs(page, 'Dépenses');

    // Changer la période recalcule la section (re-render des cartes) sans casser la page.
    const periodeSelect = page.locator('.p-dropdown').first();
    if (await periodeSelect.isVisible({ timeout: 5_000 }).catch(() => false)) {
        await periodeSelect.click();
        const option = page.getByRole('option').first();
        if (await option.isVisible({ timeout: 3_000 }).catch(() => false)) {
            await option.click();
            await expect(page).toHaveURL(
                /\/comptabilite\/commissions\/vente\/livreurs\//,
                { timeout: 15_000 },
            );
            await assertSummaryCardsAndTabs(page, 'Dépenses');
        }
    }
});

test('détail Commission propriétaire — libellé « Dépenses véhicules »', async ({
    page,
}) => {
    await login(page);
    await page.goto('/comptabilite/commissions/proprietaires');

    const row = page.locator('tbody tr:has(td)').first();
    const hasRow = await row.isVisible({ timeout: 10_000 }).catch(() => false);
    test.skip(
        !hasRow,
        'Aucun propriétaire seedé pour Commission propriétaire dans cet environnement (CommissionsSeeder désactivé).',
    );

    await row.click();

    await expect(page).toHaveURL(
        /\/comptabilite\/commissions\/proprietaires\/[a-z0-9]+$/,
        { timeout: 20_000 },
    );

    await assertSummaryCardsAndTabs(page, 'Dépenses véhicules');

    await page.getByRole('button', { name: 'Dépenses', exact: false }).click();
    await expect(page.locator('body')).toContainText(
        /véhicules|aucune dépense/i,
    );
});

/**
 * Refactor filtres globaux (2026-06-27) : la barre de filtres (période /
 * véhicule / agence) est désormais sous les cartes KPI, persistée dans l'URL
 * via Inertia, et identique sur les 3 modules — CommissionGlobalFilters.vue.
 */

test('filtres globaux Commission vente — URL persiste et Réinitialiser fonctionne', async ({
    page,
}) => {
    await login(page);
    await page.goto('/comptabilite/commissions/vente');

    const row = page.locator('tbody tr:has(td)').first();
    const hasRow = await row.isVisible({ timeout: 10_000 }).catch(() => false);
    test.skip(
        !hasRow,
        'Aucun bénéficiaire seedé pour Commission vente dans cet environnement (CommissionsSeeder désactivé).',
    );

    await row.click();
    await expect(page).toHaveURL(
        /\/comptabilite\/commissions\/vente\/livreurs\/[a-z0-9]+$/,
        { timeout: 20_000 },
    );

    const globalFilters = page.getByTestId('commission-global-filters');
    await expect(globalFilters).toBeVisible({ timeout: 15_000 });

    const periodeDropdown = globalFilters
        .getByTestId('commission-filters-periode')
        .getByRole('combobox');
    await periodeDropdown.click();
    const options = page.getByRole('option');
    const optionCount = await options.count();
    // L'option 0 est toujours "Toutes les périodes" (réinitialise le filtre,
    // donc omet le paramètre d'URL) — il faut une vraie période pour tester
    // la persistance du paramètre.
    test.skip(
        optionCount < 2,
        'Pas assez de périodes disponibles pour ce bénéficiaire.',
    );

    await options.nth(1).click();
    await expect(page).toHaveURL(/periode=/, { timeout: 15_000 });

    const resetButton = page.getByTestId('commission-filters-reset');
    await expect(resetButton).toBeVisible({ timeout: 10_000 });
    await resetButton.click();
    await expect(page).not.toHaveURL(/periode=/, { timeout: 15_000 });
});

test('filtres globaux présents et identiques sur Commission logistique et propriétaire', async ({
    page,
}) => {
    await login(page);

    await page.goto('/comptabilite/commissions/logistique');
    const logistiqueRow = page
        .locator('tbody tr', { hasText: /Thierno\s+SALL/i })
        .first();
    await expect(logistiqueRow).toBeVisible({ timeout: 20_000 });
    await logistiqueRow.click();
    await expect(page).toHaveURL(
        /\/comptabilite\/commissions\/logistique\/livreurs\/[a-z0-9]+$/,
        { timeout: 20_000 },
    );
    await expect(page.getByTestId('commission-global-filters')).toBeVisible({
        timeout: 15_000,
    });

    await page.goto('/comptabilite/commissions/proprietaires');
    const proprietaireRow = page.locator('tbody tr:has(td)').first();
    const hasProprietaireRow = await proprietaireRow
        .isVisible({ timeout: 10_000 })
        .catch(() => false);
    test.skip(
        !hasProprietaireRow,
        'Aucun propriétaire seedé pour Commission propriétaire dans cet environnement (CommissionsSeeder désactivé).',
    );

    await proprietaireRow.click();
    await expect(page).toHaveURL(
        /\/comptabilite\/commissions\/proprietaires\/[a-z0-9]+$/,
        { timeout: 20_000 },
    );
    await expect(page.getByTestId('commission-global-filters')).toBeVisible({
        timeout: 15_000,
    });
});
