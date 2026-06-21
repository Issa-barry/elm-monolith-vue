import { expect, test } from '@playwright/test';
import { closeFilterDrawerIfOpen, login } from './helpers';

test.setTimeout(90_000);

test.beforeEach(async ({ page }) => {
    // Un test précédent peut avoir laissé le drawer de filtres ouvert ;
    // son overlay bloquerait alors les clics sur la barre d'outils.
    await closeFilterDrawerIfOpen(page);
});

async function openFilterDrawer(page: any) {
    const btn = page.getByRole('button', { name: /filtres/i }).first();
    await expect(btn).toBeVisible({ timeout: 10_000 });
    await btn.click();
    await expect(page.getByText('Statut commande')).toBeVisible({
        timeout: 5_000,
    });
}

async function selectMultiSelectOption(page: any, optionLabel: string) {
    const panel = page
        .locator('.p-multiselect-overlay:visible, [data-pc-name="multiselect"] .p-overlay:visible')
        .first();
    const option = panel.locator(`[role="option"]`, { hasText: optionLabel }).first();
    if (!(await option.isVisible({ timeout: 3_000 }).catch(() => false))) {
        // fallback: cherche dans tout le DOM visible
        const fallback = page
            .locator(`[role="option"]:visible`, { hasText: optionLabel })
            .first();
        await expect(fallback).toBeVisible({ timeout: 5_000 });
        await fallback.click();
        return;
    }
    await option.click();
}

test.describe('Ventes — filtre multi-statut', () => {
    test('le drawer de filtres s\'ouvre et affiche le MultiSelect statut', async ({ page }) => {
        await login(page);
        await page.goto('/ventes');
        await expect(page).toHaveURL(/\/ventes/, { timeout: 15_000 });

        await openFilterDrawer(page);

        const multiselect = page
            .locator('[data-pc-name="multiselect"]')
            .filter({ hasText: /tous les statuts/i })
            .first();
        await expect(multiselect).toBeVisible({ timeout: 5_000 });
    });

    test('sélectionner un statut filtre les commandes et met à jour l\'URL', async ({ page }) => {
        await login(page);
        await page.goto('/ventes');
        await expect(page).toHaveURL(/\/ventes/, { timeout: 15_000 });

        await openFilterDrawer(page);

        // Ouvrir le MultiSelect statut
        const multiselect = page
            .locator('[data-pc-name="multiselect"]')
            .filter({ hasText: /tous les statuts/i })
            .first();
        await multiselect.click();

        // Sélectionner "Brouillon"
        await selectMultiSelectOption(page, 'Brouillon');

        // Fermer le panel en cliquant ailleurs
        await page.keyboard.press('Escape');

        // Appliquer
        await page.getByRole('button', { name: /appliquer/i }).first().click();

        // URL doit contenir statuts[]=brouillon
        await expect(page).toHaveURL(/statuts/, { timeout: 10_000 });
    });

    test('sélectionner plusieurs statuts est possible', async ({ page }) => {
        await login(page);
        await page.goto('/ventes');
        await expect(page).toHaveURL(/\/ventes/, { timeout: 15_000 });

        await openFilterDrawer(page);

        const multiselect = page
            .locator('[data-pc-name="multiselect"]')
            .filter({ hasText: /tous les statuts/i })
            .first();
        await multiselect.click();

        await selectMultiSelectOption(page, 'Brouillon');
        await selectMultiSelectOption(page, 'Clôturée');

        await page.keyboard.press('Escape');

        // Vérifier que 2 chips sont affichées
        const chips = multiselect.locator('.p-multiselect-chip, [data-pc-section="chip"]');
        await expect(chips).toHaveCount(2, { timeout: 5_000 });

        await page.getByRole('button', { name: /appliquer/i }).first().click();
        await expect(page).toHaveURL(/statuts/, { timeout: 10_000 });
    });

    test('réinitialiser efface les statuts sélectionnés', async ({ page }) => {
        await login(page);
        await page.goto('/ventes?statuts[]=brouillon');
        await expect(page).toHaveURL(/\/ventes/, { timeout: 15_000 });

        await openFilterDrawer(page);

        await page.getByRole('button', { name: /réinitialiser/i }).first().click();

        // L'URL ne doit plus contenir statuts
        await expect(page).not.toHaveURL(/statuts/, { timeout: 10_000 });
    });

    test('le filtre agence est aussi un MultiSelect (admin)', async ({ page }) => {
        await login(page);
        await page.goto('/ventes');
        await expect(page).toHaveURL(/\/ventes/, { timeout: 15_000 });

        // Le sélecteur agence est affiché inline dans la barre d'outils (pas
        // dans le drawer de filtres) : ne pas ouvrir le drawer ici, sinon son
        // overlay intercepte les clics sur ce sélecteur.
        const agenceMultiselect = page
            .getByTestId('agency-filter')
            .locator('[data-pc-name="multiselect"]')
            .filter({ hasText: /toutes les agences/i })
            .first();

        // Si le filtre agence existe (admin), il doit être un MultiSelect
        const isAdmin = await agenceMultiselect.isVisible({ timeout: 3_000 }).catch(() => false);
        if (!isAdmin) {
            test.skip();
            return;
        }

        await agenceMultiselect.click();
        const options = page.locator('[role="option"]:visible');
        await expect(options.first()).toBeVisible({ timeout: 5_000 });
        await options.first().click();

        const chips = agenceMultiselect.locator('.p-multiselect-chip, [data-pc-section="chip"]');
        await expect(chips.first()).toBeVisible({ timeout: 3_000 });
    });
});
