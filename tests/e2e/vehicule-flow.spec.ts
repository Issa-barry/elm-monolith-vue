import { expect, test } from '@playwright/test';
import {
    escapeRegExp,
    getVisibleSearchInput,
    login,
    openRowActions,
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

    await page.locator('#nom_vehicule').fill(nomVehicule);
    await page.locator('#immatriculation').fill(immatriculation);

    const comboboxes = page.locator('#vehicule-form').getByRole('combobox');

    // Ordre DOM actuel: type (Dropdown), proprietaire (AutoComplete), equipe (AutoComplete)
    await selectOptionFromCombobox(page, comboboxes.nth(0));
    await selectOptionFromCombobox(page, comboboxes.nth(1));

    await page.locator('#taux_proprietaire input').fill('100');

    await page
        .locator('#vehicule-form button[type="submit"]:visible')
        .first()
        .click();

    await expect(page).toHaveURL(/\/vehicules$/);

    const searchInput = getVisibleSearchInput(page);
    await searchInput.fill(immatriculation);

    const row = page
        .locator('tbody tr', {
            hasText: new RegExp(escapeRegExp(immatriculation), 'i'),
        })
        .first();
    await expect(row).toBeVisible();

    await openRowActions(row);
    await page
        .getByRole('menuitem', { name: /modifier/i })
        .first()
        .click();

    await expect(page).toHaveURL(/\/vehicules\/\d+\/edit$/);

    await page.locator('label[for="is_active"]').first().click();

    await page
        .locator('#vehicule-form button[type="submit"]:visible')
        .first()
        .click();

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
