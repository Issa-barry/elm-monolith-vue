/**
 * employe-telephone-flow.spec.ts
 * « Nouvel employé » commence par le téléphone (PhoneCountryInput.vue) : Guinée +224 par défaut,
 * « Rechercher » avant toute saisie, un numéro déjà enregistré n'ouvre jamais un second dossier,
 * toast de succès en haut, préremplissage correct à l'édition.
 *
 * Run: npx playwright test tests/e2e/employe-telephone-flow.spec.ts --workers=1
 */
import { expect, type Page, test } from '@playwright/test';
import {
    login,
    numeroGuineeAleatoire,
    randomDigits,
    selectOptionFromCombobox,
} from './helpers';

test.setTimeout(120_000);

test.beforeEach(async ({ page }) => {
    await login(page);
});

async function rechercherNumero(page: Page, numero: string) {
    await page.goto('/backoffice/employes/create');
    await expect(
        page.getByRole('heading', { name: /nouvel employé/i }),
    ).toBeVisible({ timeout: 15_000 });
    await page.locator('#telephone').fill(numero);
    await page.getByTestId('rechercher-telephone').click();
}

test("créer un employé à partir de son numéro guinéen, vérifier l'affichage puis modifier le pays et le numéro", async ({
    page,
}) => {
    const nom = `E2EEMP${randomDigits(6)}`;
    const numeroGuinee = numeroGuineeAleatoire();

    await page.goto('/backoffice/employes/create');
    // Guinée +224 sélectionné par défaut, sans aucune interaction.
    const telephoneField = page
        .locator('label', { hasText: /^Numéro de téléphone/ })
        .locator('xpath=..');
    await expect(telephoneField.getByText('+224')).toBeVisible({
        timeout: 15_000,
    });
    // Le reste du formulaire n'apparaît qu'après la recherche.
    await expect(page.locator('#prenom')).toHaveCount(0);

    await rechercherNumero(page, numeroGuinee);
    await expect(page.getByTestId('resultat-nouveau')).toBeVisible({
        timeout: 15_000,
    });

    await page.locator('#prenom').fill('Amara');
    await page.locator('#nom').fill(nom);
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
    // Depuis #telephone : le contact d'urgence a lui aussi un libellé « Téléphone ».
    const editTelephoneField = page
        .locator('#telephone')
        .locator('xpath=ancestor::div[label][1]');
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

test('un numéro déjà enregistré affiche le dossier existant au lieu d’en créer un second', async ({
    page,
}) => {
    const nom = `E2EEMP${randomDigits(6)}`;
    const numeroGuinee = numeroGuineeAleatoire();

    await rechercherNumero(page, numeroGuinee);
    await expect(page.getByTestId('resultat-nouveau')).toBeVisible({
        timeout: 15_000,
    });
    await page.locator('#prenom').fill('Amara');
    await page.locator('#nom').fill(nom);
    await page.getByRole('button', { name: /créer l'employé/i }).click();
    await expect(page).toHaveURL(/\/employes\/[a-z0-9]+\/edit/, {
        timeout: 15_000,
    });

    // Même numéro écrit avec un 0 initial : reconnu.
    await rechercherNumero(page, `0${numeroGuinee}`);
    const resultat = page.getByTestId('resultat-employe');
    await expect(resultat).toBeVisible({ timeout: 15_000 });
    await expect(resultat).toContainText(/un employé existe déjà/i);
    await expect(resultat).toContainText(nom);
    await expect(
        page.getByRole('button', { name: /créer l'employé/i }),
    ).toHaveCount(0);
});

test('un numéro trop court est signalé et ne permet pas la recherche', async ({
    page,
}) => {
    await page.goto('/backoffice/employes/create');

    // 3 chiffres seulement : jamais assez pour un numéro guinéen valide (9 attendus) — bloqué
    // côté client par PhoneCountryInput avant même la recherche.
    await page.locator('#telephone').fill('612');
    await page.locator('#telephone').blur();

    await expect(page.getByText(/trop court/i)).toBeVisible({
        timeout: 10_000,
    });
    await expect(page.getByTestId('rechercher-telephone')).toBeDisabled();
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
        .locator('label', { hasText: /^Numéro de téléphone/ })
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
    await expect(page.getByTestId('rechercher-telephone')).toBeVisible();
});
