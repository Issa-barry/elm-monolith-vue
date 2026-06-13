import { expect, test } from '@playwright/test';
import {
    escapeRegExp,
    getVisibleSearchInput,
    login,
    navigateToFirstSiteVehiclesTab,
    randomDigits,
    registerCleanup,
    selectOptionFromCombobox,
} from './helpers';

const E2E_VEHICULE_IMMATRICULATION_PREFIX = 'E2EVH-';

test.setTimeout(120_000);

registerCleanup('/vehicules', E2E_VEHICULE_IMMATRICULATION_PREFIX);

test('login + create vehicule interne via site + verify in site tab + verify global list + update', async ({
    page,
}) => {
    const unique = `${Date.now()}-${randomDigits(3)}`;
    const nomVehicule = `E2E Vehicule ${unique}`;
    const immatriculation = `${E2E_VEHICULE_IMMATRICULATION_PREFIX}${unique.slice(-6)}`;
    const nomModifie = `E2E VH Modif ${unique.slice(-6)}`;

    await login(page);

    // Step 1: Navigate to the first site's Véhicules tab
    const siteUrl = await navigateToFirstSiteVehiclesTab(page);

    // Step 2: Click "Ajouter un véhicule" — navigates to /vehicules/create?site_id=...
    await page.getByTestId('add-site-vehicle-btn').click();
    await page.waitForURL(/\/vehicules\/create\?site_id=/, { timeout: 15_000 });

    // Step 3: The submit button is initially disabled (nom + immatriculation + type missing)
    const submitBtn = page.getByTestId('vehicle-form-submit');
    await expect(submitBtn).toBeDisabled();

    // Step 4: Fill the form — categorie is locked to "interne", only type is a combobox
    await page.locator('#nom_vehicule').fill(nomVehicule);
    await page.locator('#immatriculation').fill(immatriculation);

    const comboboxes = page.locator('#vehicule-form').getByRole('combobox');
    await selectOptionFromCombobox(page, comboboxes.first()); // type_vehicule (only combobox)

    await expect(submitBtn).toBeEnabled();
    await submitBtn.click();

    // Controller redirects to the vehicle detail page after creation
    await page.waitForURL(/\/vehicules\/[a-z0-9]+$/, { timeout: 15_000 });
    const vehiculeUrl = page.url();

    // Step 5: Go back to the site and verify the vehicle appears in the Véhicules tab
    await page.goto(siteUrl);
    await page.waitForURL(/\/sites\/[a-z0-9]+$/, { timeout: 15_000 });
    await page.getByTestId('site-vehicles-tab').click();
    await expect(page.getByText(immatriculation).first()).toBeVisible({
        timeout: 10_000,
    });

    // Step 6: Verify the vehicle also appears in the global list
    await page.goto('/vehicules');
    await expect(page).toHaveURL(/\/vehicules$/);

    const searchInput = getVisibleSearchInput(page);
    await searchInput.fill(immatriculation);

    const vehiculeRow = page
        .locator('tbody tr', {
            hasText: new RegExp(escapeRegExp(immatriculation), 'i'),
        })
        .first();
    await expect(vehiculeRow).toBeVisible({ timeout: 10_000 });

    // Step 7: Edit the vehicle
    await page.goto(`${vehiculeUrl}/edit`);
    await page.waitForURL(/\/vehicules\/[a-z0-9]+\/edit$/, { timeout: 15_000 });

    await page.locator('#nom_vehicule').fill(nomModifie);

    await page
        .locator('#vehicule-form button[type="submit"]:visible')
        .first()
        .click();
    // Le contrôleur redirige vers /edit après mise à jour (vehicules.edit, pas vehicules.show)
    await page.waitForURL(/\/vehicules\/[a-z0-9]+(\/edit)?$/, { timeout: 15_000 });

    // Step 8: Verify the modification is visible in the global list
    await page.goto('/vehicules');
    const searchInput2 = getVisibleSearchInput(page);
    await searchInput2.fill(immatriculation);

    const modifiedRow = page
        .locator('tbody tr', {
            hasText: new RegExp(escapeRegExp(nomModifie), 'i'),
        })
        .first();
    await expect(modifiedRow).toBeVisible({ timeout: 10_000 });
});

test('externe — radio pris_en_charge_par_usine non sélectionné bloque le formulaire', async ({
    page,
}) => {
    await login(page);

    // /vehicules/create without context still renders the form with the categorie dropdown
    await page.goto('/vehicules/create');
    await expect(page).toHaveURL(/\/vehicules\/create$/);

    const submitBtn = page
        .locator('#vehicule-form button[type="submit"]:visible')
        .first();
    await expect(submitBtn).toBeDisabled();

    // Select "externe" category — radio buttons appear
    const comboboxes = page.locator('#vehicule-form').getByRole('combobox');
    await selectOptionFromCombobox(page, comboboxes.first(), /externe/i);

    // Button still disabled (no Oui/Non, no proprietaire, no type)
    await expect(submitBtn).toBeDisabled();

    // Select "Non" radio
    await page
        .locator(
            '#vehicule-form input[type="radio"][name="pris_en_charge_par_usine"]',
        )
        .nth(1)
        .check();

    await expect(
        page
            .locator(
                '#vehicule-form input[type="radio"][name="pris_en_charge_par_usine"]',
            )
            .nth(1),
    ).toBeChecked();
});

test('externe — radio pris_en_charge_par_usine Oui sélectionnable', async ({
    page,
}) => {
    await login(page);

    await page.goto('/vehicules/create');
    await expect(page).toHaveURL(/\/vehicules\/create$/);

    const comboboxes = page.locator('#vehicule-form').getByRole('combobox');
    await selectOptionFromCombobox(page, comboboxes.first(), /externe/i);

    await page
        .locator(
            '#vehicule-form input[type="radio"][name="pris_en_charge_par_usine"]',
        )
        .first()
        .check();

    await expect(
        page
            .locator(
                '#vehicule-form input[type="radio"][name="pris_en_charge_par_usine"]',
            )
            .first(),
    ).toBeChecked();
});
