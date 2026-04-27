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

test('login + create vehicule + update status + verify list', async ({
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

    // Ordre DOM actuel: catégorie (Dropdown), type (Dropdown)
    // Sélectionner catégorie "interne" puis type — pas de propriétaire requis en interne
    await selectOptionFromCombobox(page, comboboxes.nth(0)); // catégorie
    await selectOptionFromCombobox(page, comboboxes.nth(1)); // type
    await expect(submitBtn).toBeEnabled();

    await submitBtn.click();

    // Controller redirects to the edit page after creation (not the list)
    await expect(page).toHaveURL(/\/vehicules\/[a-z0-9]+\/edit$/, { timeout: 15_000 });

    // Toggle is_active directly on the edit page we landed on
    await page.locator('label[for="is_active"]').first().click();

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


