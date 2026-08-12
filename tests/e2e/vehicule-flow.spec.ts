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

test('login + create vehicule via site + verify in site tab + verify global list + update', async ({
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

    // Step 3: The submit button is initially disabled (nom + immatriculation + type + usage missing)
    const submitBtn = page.getByTestId('vehicle-form-submit');
    await expect(submitBtn).toBeDisabled();

    // Step 4: Fill the form — plus de champ "catégorie" (interne/externe) : tout
    // véhicule se rattache à un site et, optionnellement, à un propriétaire
    // (défaut : organisation). Au moins un "usage" (vente/logistique) est requis.
    await page.locator('#nom_vehicule').fill(nomVehicule);
    await page.locator('#immatriculation').fill(immatriculation);

    await selectOptionFromCombobox(page, page.locator('#type_vehicule'));

    await page.getByRole('checkbox', { name: /livraison vente/i }).check();

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
    await page.waitForURL(/\/vehicules\/[a-z0-9]+(\/edit)?$/, {
        timeout: 15_000,
    });

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

test('décocher tous les usages bloque le formulaire, même avec le reste rempli', async ({
    page,
}) => {
    const unique = `${Date.now()}-${randomDigits(3)}`;

    await login(page);

    await page.goto('/backoffice/vehicules/create');
    await expect(page).toHaveURL(/\/vehicules\/create$/);

    const submitBtn = page
        .locator('#vehicule-form button[type="submit"]:visible')
        .first();
    await expect(submitBtn).toBeDisabled();

    await page
        .locator('#nom_vehicule')
        .fill(`E2E Vehicule Sans Usage ${unique}`);
    await page
        .locator('#immatriculation')
        .fill(`${E2E_VEHICULE_IMMATRICULATION_PREFIX}U-${unique.slice(-5)}`);
    await selectOptionFromCombobox(page, page.locator('#type_vehicule'));
    if (
        await page
            .locator('#site_id')
            .isVisible()
            .catch(() => false)
    ) {
        await selectOptionFromCombobox(page, page.locator('#site_id'));
    }

    // "Livraison vente" est cochée par défaut (cf. Vehicules/Create.vue) : le
    // formulaire est déjà valide une fois nom/immatriculation/type(+site) remplis.
    await expect(submitBtn).toBeEnabled();

    // La décocher sans rien cocher d'autre doit rebloquer le formulaire
    // (cf. VehiculeForm.vue::canSubmit -> auMoinsUnUsage).
    await page.getByRole('checkbox', { name: /livraison vente/i }).uncheck();
    await expect(submitBtn).toBeDisabled();
});

test('cocher un seul usage (vente ou logistique) suffit à activer le formulaire', async ({
    page,
}) => {
    const unique = `${Date.now()}-${randomDigits(3)}`;

    await login(page);

    await page.goto('/backoffice/vehicules/create');
    await expect(page).toHaveURL(/\/vehicules\/create$/);

    const submitBtn = page
        .locator('#vehicule-form button[type="submit"]:visible')
        .first();

    await page
        .locator('#nom_vehicule')
        .fill(`E2E Vehicule Logistique ${unique}`);
    await page
        .locator('#immatriculation')
        .fill(`${E2E_VEHICULE_IMMATRICULATION_PREFIX}L-${unique.slice(-5)}`);
    await selectOptionFromCombobox(page, page.locator('#type_vehicule'));
    if (
        await page
            .locator('#site_id')
            .isVisible()
            .catch(() => false)
    ) {
        await selectOptionFromCombobox(page, page.locator('#site_id'));
    }

    // Repartir d'un état sans aucun usage coché ("Livraison vente" décochée,
    // elle est cochée par défaut — cf. Vehicules/Create.vue).
    await page.getByRole('checkbox', { name: /livraison vente/i }).uncheck();
    await expect(submitBtn).toBeDisabled();

    // "Logistique / transfert" seul (sans "Livraison vente") suffit déjà.
    const logistiqueCheckbox = page.getByRole('checkbox', {
        name: /logistique.*transfert/i,
    });
    await logistiqueCheckbox.check();
    await expect(logistiqueCheckbox).toBeChecked();
    await expect(submitBtn).toBeEnabled();
});

test('show — vehicule externe : bouton "Voir la fiche propriétaire" visible, affiche nom/téléphone et navigue', async ({
    page,
}) => {
    await login(page);
    await page.goto('/backoffice/vehicules');

    // Find first show-page link in the desktop DataTable
    await page.waitForSelector(
        '.p-datatable-tbody a[href*="/backoffice/vehicules/"]',
        {
            timeout: 15_000,
        },
    );
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
        await page.waitForSelector(
            '.p-datatable-tbody a[href*="/backoffice/vehicules/"]',
            {
                timeout: 10_000,
            },
        );
    }

    expect(
        vehiculeHref,
        'Aucun véhicule externe avec propriétaire trouvé',
    ).toBeTruthy();

    await page.goto(vehiculeHref!);
    await page.waitForURL(/\/vehicules\/[a-z0-9]+$/, { timeout: 10_000 });

    await expect(page.getByTestId('proprietaire-nom')).toBeVisible({
        timeout: 10_000,
    });
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

test("show — véhicule créé sans propriétaire choisi : propriétaire par défaut de l'organisation affiché", async ({
    page,
}) => {
    const unique = `${Date.now()}-${randomDigits(3)}`;
    const nomVehicule = `E2E VH Defaut ${unique}`;
    const immatriculation = `${E2E_VEHICULE_IMMATRICULATION_PREFIX}D-${unique.slice(-5)}`;

    await login(page);

    // Créé via l'onglet Véhicules d'un site, sans jamais toucher au champ
    // Propriétaire (facultatif) — il doit être auto-attribué au propriétaire
    // par défaut de l'organisation (cf. VehiculeController::defaultProprietaireInterneId).
    await navigateToFirstSiteVehiclesTab(page);
    await page.getByTestId('add-site-vehicle-btn').click();
    await page.waitForURL(/\/vehicules\/create\?site_id=/, { timeout: 15_000 });

    await page.locator('#nom_vehicule').fill(nomVehicule);
    await page.locator('#immatriculation').fill(immatriculation);
    await selectOptionFromCombobox(page, page.locator('#type_vehicule'));
    await page.getByRole('checkbox', { name: /livraison vente/i }).check();

    await page.getByTestId('vehicle-form-submit').click();
    await page.waitForURL(/\/vehicules\/[a-z0-9]+$/, { timeout: 15_000 });

    // Le bouton doit être visible et pointer vers la fiche du propriétaire
    // par défaut de l'organisation.
    await expect(page.getByTestId('voir-fiche-proprietaire-btn')).toBeVisible({
        timeout: 5_000,
    });
});
