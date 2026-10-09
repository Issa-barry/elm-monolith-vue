import { expect, test } from '@playwright/test';
import {
    ensureModuleEnabled,
    escapeRegExp,
    login,
    openRowActions,
    randomDigits,
    selectOptionFromCombobox,
} from './helpers';

test.setTimeout(240_000);

test.beforeEach(async ({ page }) => {
    await login(page);
    await ensureModuleEnabled(page, 'module.achats');
});

// Bon de commande direct (ADR 0021) : agence, fournisseur et produit obligatoires, puis
// annulation et suppression. La validation n'est pas jouée ici : l'auteur du bon ne peut pas
// valider son propre bon (séparation des tâches), couvert par CommandeAchatTest.
test('create bon de commande -> annuler -> supprimer depuis la liste', async ({
    page,
}) => {
    const note = `E2E-ACHAT-${Date.now()}${randomDigits(2)}`.slice(-18);

    await page.goto('/backoffice/achats/create');
    await expect(page).toHaveURL(/\/achats\/create$/, { timeout: 20_000 });

    await selectOptionFromCombobox(page, page.locator('#achat-site'));
    await selectOptionFromCombobox(page, page.locator('#achat-fournisseur'));
    await selectOptionFromCombobox(page, page.locator('#ligne-variante-0'));
    await page.locator('#achat-note').fill(note);

    const submit = page
        .getByRole('button', { name: /enregistrer le bon de commande/i })
        .filter({ visible: true })
        .first();
    await expect(submit).toBeEnabled({ timeout: 15_000 });
    await submit.click();

    await expect(page).toHaveURL(/\/achats\/[a-z0-9]+$/, { timeout: 30_000 });
    await expect(page.getByText(/en attente de validation/i)).toBeVisible({
        timeout: 15_000,
    });
    const reference = (
        await page
            .locator('h1.font-mono')
            .filter({ visible: true })
            .first()
            .innerText()
    ).trim();
    expect(reference).toMatch(/^BC-/);

    await page
        .getByRole('button', { name: /^annuler$/i })
        .first()
        .click();
    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /annuler la commande/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });
    await dialog.locator('#motif-annulation').fill('Annulation e2e');
    await dialog
        .getByRole('button', { name: /confirmer l'annulation/i })
        .click();

    await expect(page.getByText(/annulée le/i)).toBeVisible({
        timeout: 20_000,
    });

    await page.goto(
        `/backoffice/achats?reference=${encodeURIComponent(reference)}`,
    );
    const row = page
        .locator('table tbody tr', {
            hasText: new RegExp(escapeRegExp(reference), 'i'),
        })
        .first();
    await expect(row).toBeVisible({ timeout: 15_000 });

    await openRowActions(row);
    await page
        .getByRole('menuitem', { name: /supprimer/i })
        .first()
        .click();
    await page
        .getByRole('button', { name: /supprimer/i })
        .last()
        .click();

    await page.waitForLoadState('networkidle');
    await page.goto(
        `/backoffice/achats?reference=${encodeURIComponent(reference)}`,
    );
    await expect(
        page.locator('table tbody tr', {
            hasText: new RegExp(escapeRegExp(reference), 'i'),
        }),
    ).toHaveCount(0);
});
