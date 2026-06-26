import { expect, test } from '@playwright/test';
import { login } from './helpers';

test.setTimeout(120_000);

/**
 * Vérifie que les 3 pages détail commission (Vente / Logistique / Propriétaire)
 * partagent désormais la même UI : 5 cartes résumé, mêmes tabs, même dialog de
 * paiement. La logistique s'appuie sur CommissionLogistiqueSeeder (toujours actif
 * dans DatabaseSeeder). Vente et Propriétaire dépendent de CommissionsSeeder, qui
 * est désactivé dans DatabaseSeeder — ces deux tests prennent donc la première
 * ligne disponible et se "skip" proprement si la liste est vide plutôt que
 * d'échouer sur des données qui n'existent pas dans cet environnement.
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
        await expect(page.getByText(label, { exact: true })).toBeVisible({
            timeout: 15_000,
        });
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
        .locator('tbody tr', { hasText: /Aissatou\s+BALD/i })
        .first();
    await expect(row).toBeVisible({ timeout: 20_000 });
    await row.click();

    await expect(page).toHaveURL(
        /\/comptabilite\/commissions\/logistique\/livreurs\/[a-z0-9]+$/,
        { timeout: 20_000 },
    );

    await assertSummaryCardsAndTabs(page, 'Frais');

    // Onglet Dépenses désormais disponible pour la logistique.
    await page.getByRole('button', { name: 'Dépenses', exact: false }).click();
    await expect(page.locator('body')).toBeVisible();

    // Bouton Payer présent (solde impayé) et ouvre le dialog partagé.
    const payButton = page.getByRole('button', { name: /^payer/i });
    await expect(payButton).toBeVisible({ timeout: 10_000 });
    await payButton.click();

    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /Aissatou/i });
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

    await assertSummaryCardsAndTabs(page, 'Frais');

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
            await assertSummaryCardsAndTabs(page, 'Frais');
        }
    }
});

test('détail Commission propriétaire — libellé « Frais véhicules »', async ({
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

    await assertSummaryCardsAndTabs(page, 'Frais véhicules');

    await page.getByRole('button', { name: 'Dépenses', exact: false }).click();
    await expect(page.locator('body')).toContainText(
        /véhicules|aucune dépense/i,
    );
});
