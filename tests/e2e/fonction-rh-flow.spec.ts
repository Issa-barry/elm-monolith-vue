/**
 * fonction-rh-flow.spec.ts
 * CRUD des fonctions RH — isolées par organisation, aucune fonction système/suggestion
 * (décision finale du 2026-08-21, cf. plan "fonctions RH / profils d'accès / sites"). Création
 * et modification exclusivement en popup, jamais de navigation vers une page dédiée — mirroring
 * l'UX de Produits/Categories (2026-08-21).
 *
 * Run: npx playwright test tests/e2e/fonction-rh-flow.spec.ts --workers=1
 */
import { expect, test } from '@playwright/test';
import { login, randomDigits } from './helpers';

test.describe('création inline depuis un autre formulaire', () => {
    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test("créer une fonction RH en popup depuis le formulaire de création d'un employé, sans quitter la page", async ({
        page,
    }) => {
        const nom = `E2EEMP${randomDigits(6)}`;
        const libelle = `E2EFON-${randomDigits(6)}`;

        await page.goto('/backoffice/employes/create');
        await expect(
            page.getByRole('heading', { name: /nouvel employé/i }),
        ).toBeVisible({ timeout: 15_000 });

        await page
            .locator('form')
            .locator('input[type="text"]')
            .first()
            .fill('Amara');
        await page
            .locator('form')
            .locator('input[type="text"]')
            .nth(1)
            .fill(nom);

        const fonctionCombobox = page
            .locator('label', { hasText: /^Fonction RH$/ })
            .locator('xpath=..')
            .getByRole('combobox')
            .first();
        await fonctionCombobox.click();

        await page.getByRole('button', { name: /créer une fonction/i }).click();
        // Dialog PrimeVue : le titre n'est pas exposé en role="heading" (contrairement à un
        // <h1>/<h2> classique) — cf. le pattern déjà établi ailleurs dans ce projet
        // (equipe-flow.spec.ts, achat-flow.spec.ts...) : cibler [role="dialog"] directement.
        await expect(
            page
                .locator('[role="dialog"]')
                .filter({ hasText: /créer une fonction rh/i }),
        ).toBeVisible({ timeout: 10_000 });

        await page.locator('#fonction-rh-libelle').fill(libelle);
        await page.getByRole('button', { name: /^créer$/i }).click();

        // Jamais de navigation : toujours sur le formulaire employé, fonction auto-sélectionnée.
        await expect(page).toHaveURL(/\/employes\/create$/);
        await expect(fonctionCombobox).toContainText(libelle, {
            timeout: 10_000,
        });
    });
});

test.setTimeout(90_000);

test.beforeEach(async ({ page }) => {
    await login(page);
});

test('créer une fonction RH via la popup puis la retrouver dans la liste', async ({
    page,
}) => {
    const libelle = `E2EFON-${randomDigits(6)}`;

    await page.goto('/backoffice/fonctions-rh');
    await expect(
        page.getByRole('heading', { name: /fonctions rh/i }),
    ).toBeVisible({ timeout: 15_000 });

    await page.getByRole('button', { name: /nouvelle fonction/i }).click();
    await expect(
        page
            .locator('[role="dialog"]')
            .filter({ hasText: /créer une fonction rh/i }),
    ).toBeVisible({ timeout: 10_000 });

    await page.locator('#libelle').fill(libelle);
    await page.getByRole('button', { name: /^créer$/i }).click();

    // Jamais de navigation : la popup se ferme, la ligne apparaît sur la même page.
    await expect(page).toHaveURL(/\/fonctions-rh$/);
    await expect(page.getByText(libelle).first()).toBeVisible({
        timeout: 10_000,
    });
});

test('un libellé déjà utilisé dans la même organisation est refusé', async ({
    page,
}) => {
    const libelle = `E2EFON-${randomDigits(6)}`;

    await page.goto('/backoffice/fonctions-rh');
    await page.getByRole('button', { name: /nouvelle fonction/i }).click();
    await page.locator('#libelle').fill(libelle);
    await page.getByRole('button', { name: /^créer$/i }).click();
    await expect(page.getByText(libelle).first()).toBeVisible({
        timeout: 10_000,
    });

    await page.getByRole('button', { name: /nouvelle fonction/i }).click();
    await page.locator('#libelle').fill(libelle);
    await page.getByRole('button', { name: /^créer$/i }).click();

    await expect(page.getByText(/existe déjà/i)).toBeVisible({
        timeout: 10_000,
    });
});

test('désactiver puis réactiver une fonction — jamais de suppression', async ({
    page,
}) => {
    const libelle = `E2EFON-${randomDigits(6)}`;

    await page.goto('/backoffice/fonctions-rh');
    await page.getByRole('button', { name: /nouvelle fonction/i }).click();
    await page.locator('#libelle').fill(libelle);
    await page.getByRole('button', { name: /^créer$/i }).click();

    const row = page.locator('tbody tr', { hasText: libelle }).first();
    await expect(row).toBeVisible({ timeout: 10_000 });
    await expect(row.getByText(/^active$/i)).toBeVisible();

    await row.locator('button').last().click();
    await page.getByRole('menuitem', { name: /désactiver/i }).click();

    await expect(row.getByText(/^inactive$/i)).toBeVisible({
        timeout: 10_000,
    });

    await row.locator('button').last().click();
    await page.getByRole('menuitem', { name: /activer/i }).click();
    await expect(row.getByText(/^active$/i)).toBeVisible({ timeout: 10_000 });
});

test("modifier le libellé d'une fonction via la popup", async ({ page }) => {
    const libelle = `E2EFON-${randomDigits(6)}`;
    const nouveauLibelle = `E2EFON-EDIT-${randomDigits(6)}`;

    await page.goto('/backoffice/fonctions-rh');
    await page.getByRole('button', { name: /nouvelle fonction/i }).click();
    await page.locator('#libelle').fill(libelle);
    await page.getByRole('button', { name: /^créer$/i }).click();

    const row = page.locator('tbody tr', { hasText: libelle }).first();
    await expect(row).toBeVisible({ timeout: 10_000 });

    await row.locator('button').last().click();
    await page.getByRole('menuitem', { name: /modifier/i }).click();
    await expect(
        page
            .locator('[role="dialog"]')
            .filter({ hasText: /modifier la fonction/i }),
    ).toBeVisible({ timeout: 10_000 });

    await page.locator('#libelle').fill(nouveauLibelle);
    await page.getByRole('button', { name: /^enregistrer$/i }).click();

    await expect(page.getByText(nouveauLibelle).first()).toBeVisible({
        timeout: 10_000,
    });
});
