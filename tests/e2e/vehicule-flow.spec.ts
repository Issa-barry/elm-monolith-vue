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

registerCleanup('/backoffice/vehicules', E2E_VEHICULE_IMMATRICULATION_PREFIX);

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
    await page.goto('/backoffice/vehicules');
    await expect(page).toHaveURL(/\/vehicules$/);

    const searchInput = getVisibleSearchInput(page);
    await searchInput.fill(immatriculation);
    await searchInput.press('Enter');
    await page.waitForLoadState('networkidle');

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
    await page.goto('/backoffice/vehicules');
    const searchInput2 = getVisibleSearchInput(page);
    await searchInput2.fill(immatriculation);
    await searchInput2.press('Enter');
    await page.waitForLoadState('networkidle');

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

    await page.goto('/backoffice/vehicules/create');
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

    await page.goto('/backoffice/vehicules/create');
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

test('show — vehicule externe : bouton "Voir la fiche propriétaire" visible, affiche nom/téléphone et navigue', async ({
    page,
}) => {
    await login(page);
    await page.goto('/backoffice/vehicules');

    // Find first show-page link in the desktop DataTable
    await page.waitForSelector('.p-datatable-tbody a[href*="/backoffice/vehicules/"]', {
        timeout: 15_000,
    });
    const links = page.locator(
        '.p-datatable-tbody a[href*="/backoffice/vehicules/"]:not([href*="/edit"])',
    );

    // Iterate through the first few vehicules until we find one with a proprietaire
    let vehiculeHref: string | null = null;
    const total = await links.count();
    for (let i = 0; i < Math.min(total, 10); i++) {
        const href = await links.nth(i).getAttribute('href');
        if (!href) continue;

        await page.goto(href);
        await page.waitForURL(/\/vehicules\/[a-z0-9]+$/, { timeout: 10_000 });

        if (
            await page
                .getByTestId('voir-fiche-proprietaire-btn')
                .isVisible({ timeout: 3_000 })
                .catch(() => false)
        ) {
            vehiculeHref = href;
            break;
        }

        await page.goto('/backoffice/vehicules');
        await page.waitForSelector('.p-datatable-tbody a[href*="/backoffice/vehicules/"]', {
            timeout: 10_000,
        });
    }

    expect(vehiculeHref, 'Aucun véhicule externe avec propriétaire trouvé').toBeTruthy();

    await page.goto(vehiculeHref!);
    await page.waitForURL(/\/vehicules\/[a-z0-9]+$/, { timeout: 10_000 });

    await expect(
        page.getByTestId('proprietaire-nom'),
    ).toBeVisible({ timeout: 10_000 });
    await expect(page.getByTestId('proprietaire-telephone')).toBeVisible();

    const btn = page.getByTestId('voir-fiche-proprietaire-btn');
    await expect(btn).toBeVisible();

    // Verify the link points to the proprietaire page and navigate there directly
    // (waitForEvent('page') is unreliable in headless CI when popups may be blocked)
    const href = await btn.getAttribute('href');
    expect(href).toMatch(/\/proprietaires\/[a-z0-9]+$/);
    await expect(btn).toHaveAttribute('target', '_blank');

    await page.goto(href!);
    await page.waitForURL(/\/proprietaires\/[a-z0-9]+$/, { timeout: 15_000 });
    await expect(page).toHaveURL(/\/proprietaires\/[a-z0-9]+$/);
});

test('show — vehicule interne : bouton "Voir la fiche propriétaire" absent', async ({
    page,
}) => {
    const unique = `${Date.now()}-${randomDigits(3)}`;
    const nomVehicule = `E2E VH Interne ${unique}`;
    const immatriculation = `${E2E_VEHICULE_IMMATRICULATION_PREFIX}I-${unique.slice(-5)}`;

    await login(page);

    // Create an interne vehicule via the site tab (no proprietaire)
    await navigateToFirstSiteVehiclesTab(page);
    await page.getByTestId('add-site-vehicle-btn').click();
    await page.waitForURL(/\/vehicules\/create\?site_id=/, { timeout: 15_000 });

    await page.locator('#nom_vehicule').fill(nomVehicule);
    await page.locator('#immatriculation').fill(immatriculation);

    const comboboxes = page.locator('#vehicule-form').getByRole('combobox');
    await selectOptionFromCombobox(page, comboboxes.first());

    await page.getByTestId('vehicle-form-submit').click();
    await page.waitForURL(/\/vehicules\/[a-z0-9]+$/, { timeout: 15_000 });

    // Interne vehicule has no proprietaire — button must not be rendered
    await expect(
        page.getByTestId('voir-fiche-proprietaire-btn'),
    ).not.toBeVisible({ timeout: 5_000 });
});
