import { expect, test } from '@playwright/test';
import { closeFilterDrawerIfOpen, login } from './helpers';

test.setTimeout(90_000);

test.beforeEach(async ({ page }) => {
    await closeFilterDrawerIfOpen(page);
});

// ── Pilote ClickableTableRow sur Commission vente (liste livreurs) ───────────
// Vérifie les 5 comportements demandés : clic ligne -> détail, clic "..." ->
// pas de détail, hover visible, Entrée -> détail, Espace -> détail.

test('ligne cliquable Commission vente : clic, clavier, et menu "..." isolé', async ({
    page,
}) => {
    await login(page);
    await page.goto('/comptabilite/commissions/vente');
    await expect(page).toHaveURL(/\/comptabilite\/commissions\/vente/, {
        timeout: 15_000,
    });

    const rows = page.locator('tbody tr[role="link"]');
    const rowCount = await rows.count();
    if (rowCount === 0) {
        test.skip();
        return;
    }

    const firstRow = rows.first();

    // Curseur pointer + hover visible (classes utilitaires présentes).
    await expect(firstRow).toHaveClass(/cursor-pointer/);
    await expect(firstRow).toHaveClass(/hover:bg-muted\/50/);

    // 1. Clic sur le menu "..." ne doit PAS naviguer.
    const actionsCell = firstRow.locator('td').last();
    const menuButton = actionsCell.locator('button').first();
    await menuButton.click();
    await expect(page).toHaveURL(/\/comptabilite\/commissions\/vente$/);
    await page.keyboard.press('Escape');

    // 2. Clic sur la ligne (hors zone interactive) -> navigation vers le détail.
    const firstCell = firstRow.locator('td').first();
    await firstCell.click();
    await expect(page).toHaveURL(
        /\/comptabilite\/commissions\/vente\/livreurs\/[^/]+$/,
        { timeout: 10_000 },
    );

    // Retour à la liste pour le test clavier.
    await page.goBack();
    await expect(page).toHaveURL(/\/comptabilite\/commissions\/vente$/, {
        timeout: 10_000,
    });

    // 3. Entrée clavier sur la ligne focalisée -> navigation.
    const rowAgain = page.locator('tbody tr[role="link"]').first();
    await rowAgain.focus();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(
        /\/comptabilite\/commissions\/vente\/livreurs\/[^/]+$/,
        { timeout: 10_000 },
    );

    await page.goBack();
    await expect(page).toHaveURL(/\/comptabilite\/commissions\/vente$/, {
        timeout: 10_000,
    });

    // 4. Espace clavier sur la ligne focalisée -> navigation.
    const rowThirdTime = page.locator('tbody tr[role="link"]').first();
    await rowThirdTime.focus();
    await page.keyboard.press(' ');
    await expect(page).toHaveURL(
        /\/comptabilite\/commissions\/vente\/livreurs\/[^/]+$/,
        { timeout: 10_000 },
    );
});
