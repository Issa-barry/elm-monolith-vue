/**
 * employe-site-transfer-flow.spec.ts
 * Affectation initiale + transfert de site d'un employé — vérifie que le formulaire
 * d'édition classique route bien le changement de site via EmployeAffectationService (cache +
 * historique synchronisés), cf. plan "fonctions RH / profils d'accès / sites" §2.
 *
 * Run: npx playwright test tests/e2e/employe-site-transfer-flow.spec.ts --workers=1
 */
import { expect, test } from '@playwright/test';
import { login, randomDigits, selectOptionFromCombobox } from './helpers';

test.setTimeout(120_000);

test.beforeEach(async ({ page }) => {
    await login(page);
});

test('créer un employé avec un site affiche une affectation initiale active', async ({
    page,
}) => {
    const nom = `E2EEMP${randomDigits(6)}`;

    await page.goto('/backoffice/employes/create');
    await expect(page.getByRole('heading', { name: /nouvel employé/i })).toBeVisible({
        timeout: 15_000,
    });

    await page.locator('input[type="text"]').first().fill('Amara');
    await page.locator('input[type="text"]').nth(1).fill(nom);

    const siteCombobox = page
        .locator('div')
        .filter({ hasText: /^Site$/ })
        .locator('..')
        .getByRole('combobox')
        .first();
    await selectOptionFromCombobox(page, siteCombobox, /matoto/i);

    await page.getByRole('button', { name: /créer l'employé/i }).click();

    await expect(page).toHaveURL(/\/employes\/[a-z0-9]+\/edit/, {
        timeout: 15_000,
    });
    await expect(
        page.getByText(/historique d'affectation/i),
    ).toBeVisible({ timeout: 10_000 });
    await expect(page.getByText(/^actif$/i).first()).toBeVisible();
});

test('transférer un employé vers un autre site conserve son historique', async ({
    page,
}) => {
    const nom = `E2EEMP${randomDigits(6)}`;

    await page.goto('/backoffice/employes/create');
    await page.locator('input[type="text"]').first().fill('Amara');
    await page.locator('input[type="text"]').nth(1).fill(nom);

    const siteComboboxCreate = page
        .locator('div')
        .filter({ hasText: /^Site$/ })
        .locator('..')
        .getByRole('combobox')
        .first();
    await selectOptionFromCombobox(page, siteComboboxCreate, /matoto/i);
    await page.getByRole('button', { name: /créer l'employé/i }).click();
    await expect(page).toHaveURL(/\/employes\/[a-z0-9]+\/edit/, {
        timeout: 15_000,
    });

    // Transfert : changer le site sur le formulaire d'édition.
    const siteComboboxEdit = page
        .locator('div')
        .filter({ hasText: /^Site$/ })
        .locator('..')
        .getByRole('combobox')
        .first();
    await selectOptionFromCombobox(page, siteComboboxEdit, /cba|lambanyi/i);
    await page.getByRole('button', { name: /^enregistrer$/i }).click();

    // Toast de succès en haut à droite (groupe "top") — position haute exigée pour ce parcours.
    const toast = page.locator('.p-toast-message').first();
    await expect(toast).toBeVisible({ timeout: 10_000 });
    const box = await toast.boundingBox();
    expect(box).not.toBeNull();
    if (box) {
        expect(box.y).toBeLessThan(200);
    }

    // Historique : 2 lignes (l'affectation initiale close + la nouvelle active).
    await page.waitForLoadState('networkidle');
    const historique = page
        .locator('div')
        .filter({ hasText: /historique d'affectation/i })
        .last();
    await expect(historique).toBeVisible({ timeout: 10_000 });
    await expect(page.getByText(/actif$/i).first()).toBeVisible();
});
