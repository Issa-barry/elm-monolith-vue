/**
 * employe-telephone-flow.spec.ts
 * Téléphone employé avec sélecteur d'indicatif pays (PhoneCountryInput.vue, réutilisé de
 * Login.vue/Install/Wizard.vue) — Guinée +224 par défaut, jamais de double indicatif, erreurs
 * affichées sous le champ, toast de succès en haut, préremplissage correct à l'édition.
 *
 * Run: npx playwright test tests/e2e/employe-telephone-flow.spec.ts --workers=1
 */
import { expect, test } from '@playwright/test';
import { login, randomDigits, selectOptionFromCombobox } from './helpers';

test.setTimeout(120_000);

test.beforeEach(async ({ page }) => {
    await login(page);
});

test("créer un employé avec le numéro guinéen par défaut, vérifier l'affichage puis modifier le pays et le numéro", async ({
    page,
}) => {
    const nom = `E2EEMP${randomDigits(6)}`;
    // Mobile guinéen valide (libphonenumber GN) : 6[0-356]\d{7} — le 2e chiffre ne peut
    // JAMAIS être 4/7/8/9, sous peine de rejet légitime côté backend (numéro invalide).
    const numeroGuinee = `6${['0', '1', '2', '3', '5', '6'][Math.floor(Math.random() * 6)]}${randomDigits(7)}`;

    await page.goto('/backoffice/employes/create');
    await expect(
        page.getByRole('heading', { name: /nouvel employé/i }),
    ).toBeVisible({ timeout: 15_000 });

    await page
        .locator('form')
        .locator('input[type="text"]')
        .first()
        .fill('Amara');
    await page.locator('form').locator('input[type="text"]').nth(1).fill(nom);

    // Guinée +224 sélectionné par défaut, sans aucune interaction.
    const telephoneField = page
        .locator('label', { hasText: /^Téléphone$/ })
        .locator('xpath=..');
    await expect(telephoneField.getByText('+224')).toBeVisible({
        timeout: 15_000,
    });

    await page.locator('#telephone').fill(numeroGuinee);
    await page.getByRole('button', { name: /créer l'employé/i }).click();

    await expect(page).toHaveURL(/\/employes\/[a-z0-9]+\/edit/, {
        timeout: 15_000,
    });

    // Toast de succès en haut (groupe "top", cf. useFlashToast()).
    const toast = page.locator('.p-toast-message').first();
    await expect(toast).toBeVisible({ timeout: 10_000 });
    const box = await toast.boundingBox();
    expect(box).not.toBeNull();
    if (box) expect(box.y).toBeLessThan(200);

    // Préremplissage à l'édition : pays + numéro national correctement restitués, jamais
    // "+224 +224...".
    const editTelephoneField = page
        .locator('label', { hasText: /^Téléphone$/ })
        .locator('xpath=..');
    await expect(editTelephoneField.getByText('+224')).toBeVisible({
        timeout: 15_000,
    });
    await expect(page.locator('#telephone')).toHaveValue(numeroGuinee, {
        timeout: 15_000,
    });
    await expect(page.locator('#telephone')).not.toHaveValue(
        new RegExp(`\\+224.*${numeroGuinee}`),
    );

    // Changer l'indicatif et le numéro.
    const countryCombobox = editTelephoneField.getByRole('combobox').first();
    await selectOptionFromCombobox(page, countryCombobox, /france/i);
    await page.locator('#telephone').fill('612345678');

    await page.getByRole('button', { name: /^enregistrer$/i }).click();

    await expect(page.locator('.p-toast-message').first()).toBeVisible({
        timeout: 15_000,
    });
    await expect(editTelephoneField.getByText('+33')).toBeVisible({
        timeout: 15_000,
    });
    await expect(page.locator('#telephone')).toHaveValue('612345678', {
        timeout: 15_000,
    });
});

test('un numéro invalide est refusé avec une erreur sous le champ téléphone', async ({
    page,
}) => {
    const nom = `E2EEMP${randomDigits(6)}`;

    await page.goto('/backoffice/employes/create');
    await page
        .locator('form')
        .locator('input[type="text"]')
        .first()
        .fill('Amara');
    await page.locator('form').locator('input[type="text"]').nth(1).fill(nom);

    // 3 chiffres seulement : jamais assez pour un numéro guinéen valide (9 attendus) — bloqué
    // côté client par PhoneCountryInput avant même la soumission serveur.
    await page.locator('#telephone').fill('612');
    await page.locator('#telephone').blur();

    await expect(page.getByText(/trop court/i)).toBeVisible({
        timeout: 10_000,
    });
});

test('le sélecteur de téléphone reste utilisable sur petit écran', async ({
    page,
}) => {
    await page.setViewportSize({ width: 375, height: 812 });

    await page.goto('/backoffice/employes/create');
    await expect(
        page.getByRole('heading', { name: /nouvel employé/i }),
    ).toBeVisible({ timeout: 15_000 });

    const telephoneField = page
        .locator('label', { hasText: /^Téléphone$/ })
        .locator('xpath=..');
    const countryCombobox = telephoneField.getByRole('combobox').first();
    const phoneInput = page.locator('#telephone');

    await expect(countryCombobox).toBeVisible();
    await expect(phoneInput).toBeVisible();

    // Les deux éléments restent sur la même ligne visuelle (pas d'empilement vertical cassé).
    const countryBox = await countryCombobox.boundingBox();
    const inputBox = await phoneInput.boundingBox();
    expect(countryBox).not.toBeNull();
    expect(inputBox).not.toBeNull();
    if (countryBox && inputBox) {
        expect(Math.abs(countryBox.y - inputBox.y)).toBeLessThan(10);
    }
});
