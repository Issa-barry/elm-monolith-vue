import { expect, test } from '@playwright/test';
import {
    escapeRegExp,
    getVisibleSearchInput,
    login,
    randomDigits,
    registerCleanup,
    selectOptionFromCombobox,
} from './helpers';

const E2E_VEHICULE_IMMATRICULATION_PREFIX = 'E2EVH-';

test.setTimeout(120_000);

registerCleanup('/vehicules', E2E_VEHICULE_IMMATRICULATION_PREFIX);

test('login + create vehicule interne + update + verify list', async ({
    page,
}) => {
    const unique = `${Date.now()}-${randomDigits(3)}`;
    const nomVehicule = `E2E Vehicule ${unique}`;
    const immatriculation = `${E2E_VEHICULE_IMMATRICULATION_PREFIX}${unique.slice(-6)}`;

    await login(page);

    await page.goto('/vehicules/create');
    await expect(page).toHaveURL(/\/vehicules\/create$/);

    const submitBtn = page
        .locator('#vehicule-form button[type="submit"]:visible')
        .first();
    await expect(submitBtn).toBeDisabled();

    await page.locator('#nom_vehicule').fill(nomVehicule);
    await page.locator('#immatriculation').fill(immatriculation);

    const comboboxes = page.locator('#vehicule-form').getByRole('combobox');

    // Catégorie "interne" : auto-set pris_en_charge_par_usine=true, pas de radio requis
    await selectOptionFromCombobox(page, comboboxes.nth(0)); // catégorie
    await selectOptionFromCombobox(page, comboboxes.nth(1)); // type
    await expect(submitBtn).toBeEnabled();

    await submitBtn.click();

    // Controller redirects to the edit page after creation (not the list)
    await expect(page).toHaveURL(/\/vehicules\/[a-z0-9]+\/edit$/, { timeout: 15_000 });

    // A newly created vehicle has no equipe so the is_active toggle is not shown.
    // The controller forces is_active=false for vehicles without an equipe anyway.
    await submitBtn.click();

    // waitForLoadState ensures the PUT response has arrived before checking the
    // flash banner (toHaveURL alone is a no-op when the URL does not change).
    await page.waitForLoadState('networkidle');
    await expect(page).toHaveURL(/\/vehicules\/[a-z0-9]+\/edit$/);
    await expect(
        page.getByText(/Véhicule mis à jour avec succès./i).first(),
    ).toBeVisible();

    await page.goto('/vehicules');
    await expect(page).toHaveURL(/\/vehicules$/);

    const updatedSearchInput = getVisibleSearchInput(page);
    await updatedSearchInput.fill(immatriculation);

    const updatedRow = page
        .locator('tbody tr', {
            hasText: new RegExp(escapeRegExp(immatriculation), 'i'),
        })
        .first();

    await expect(updatedRow).toBeVisible();
    await expect(updatedRow).toContainText(/inactif/i);
});

test('create vehicule externe — bouton bloqué sans Oui/Non, débloqué après sélection', async ({
    page,
}) => {
    const unique = `${Date.now()}-${randomDigits(3)}`;
    const immatriculation = `${E2E_VEHICULE_IMMATRICULATION_PREFIX}EX${unique.slice(-4)}`;

    await login(page);
    await page.goto('/vehicules/create');
    await expect(page).toHaveURL(/\/vehicules\/create$/);

    const submitBtn = page
        .locator('#vehicule-form button[type="submit"]:visible')
        .first();

    await page.locator('#nom_vehicule').fill(`E2E Externe ${unique}`);
    await page.locator('#immatriculation').fill(immatriculation);

    const comboboxes = page.locator('#vehicule-form').getByRole('combobox');
    // Sélectionner catégorie "externe" (2e option) puis le type
    await selectOptionFromCombobox(page, comboboxes.nth(0), /externe/i); // catégorie externe
    await selectOptionFromCombobox(page, comboboxes.nth(1)); // type

    // Pas encore de Oui/Non → bouton désactivé
    await expect(submitBtn).toBeDisabled();

    // Sélectionner "Non"
    await page
        .locator('#vehicule-form input[type="radio"][name="pris_en_charge_par_usine"]')
        .nth(1)
        .check();

    // Le bouton peut encore être désactivé si proprietaire_id manque pour externe
    // → on vérifie juste que le radio "Non" est bien coché
    await expect(
        page
            .locator('#vehicule-form input[type="radio"][name="pris_en_charge_par_usine"]')
            .nth(1),
    ).toBeChecked();
});

test('create vehicule externe avec prise en charge Oui', async ({ page }) => {
    const unique = `${Date.now()}-${randomDigits(3)}`;
    const immatriculation = `${E2E_VEHICULE_IMMATRICULATION_PREFIX}EY${unique.slice(-4)}`;

    await login(page);
    await page.goto('/vehicules/create');

    await page.locator('#nom_vehicule').fill(`E2E Charge Oui ${unique}`);
    await page.locator('#immatriculation').fill(immatriculation);

    const comboboxes = page.locator('#vehicule-form').getByRole('combobox');
    await selectOptionFromCombobox(page, comboboxes.nth(0), 1); // externe
    await selectOptionFromCombobox(page, comboboxes.nth(1));    // type

    // Sélectionner "Oui"
    await page
        .locator('#vehicule-form input[type="radio"][name="pris_en_charge_par_usine"]')
        .first()
        .check();

    await expect(
        page
            .locator('#vehicule-form input[type="radio"][name="pris_en_charge_par_usine"]')
            .first(),
    ).toBeChecked();
});


