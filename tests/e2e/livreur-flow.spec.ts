import { expect, test } from '@playwright/test';
import {
    findRowByName,
    login,
    openRowActions,
    randomDigits,
    registerCleanup,
    selectOptionFromCombobox,
} from './helpers';

const PREFIX = 'e2elivflow';

test.setTimeout(180_000);

registerCleanup('/livreurs', PREFIX);

async function navigateToEdit(
    page: Parameters<typeof login>[0],
    name: string,
): Promise<void> {
    const row = await findRowByName(page, name);
    await openRowActions(row);
    await page.getByRole('menuitem', { name: /modifier/i }).first().click();
    await expect(page).toHaveURL(/\/livreurs\/\d+\/edit$/);
}

async function createLivreurInApp(
    page: Parameters<typeof login>[0],
    params: { prenom: string; nom: string; tel: string; adresse?: string },
): Promise<void> {
    await page.goto('/livreurs/create');
    await page.locator('#prenom').fill(params.prenom);
    await page.locator('#nom').fill(params.nom);
    const paysCombo = page
        .locator('#livreur-form')
        .getByRole('combobox')
        .first();
    await selectOptionFromCombobox(page, paysCombo, /guinée$/i);
    await page.locator('#ville').fill('Conakry');
    if (params.adresse) {
        await page.locator('#adresse').fill(params.adresse);
    }
    await page.locator('#telephone').fill(params.tel);
    await page
        .locator('#livreur-form button[type="submit"]:visible')
        .first()
        .click();
    await expect(page).toHaveURL(/\/livreurs$/);
}

// ─── Création ────────────────────────────────────────────────────────────────

test('create livreur with all fields → verify in list', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Flow${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createLivreurInApp(page, { prenom, nom, tel, adresse: 'Quartier Matam' });

    const row = await findRowByName(page, prenom);
    await expect(row).toBeVisible();

    // Localisation affichée : adresse + ville
    await expect(row).toContainText('Quartier Matam');
    await expect(row).toContainText('Conakry');
});

// ─── Modification des champs de localisation ─────────────────────────────────

test('edit livreur → update ville / adresse → data persists', async ({
    page,
}) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Edit${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createLivreurInApp(page, { prenom, nom, tel, adresse: 'Adresse initiale' });
    await navigateToEdit(page, prenom);

    // Modifier ville et adresse
    await page.locator('#ville').clear();
    await page.locator('#ville').fill('Mamou');
    await page.locator('#adresse').clear();
    await page.locator('#adresse').fill('Adresse modifiée');

    await page
        .locator('#livreur-form button[type="submit"]:visible')
        .first()
        .click();
    // Le contrôleur redirige vers edit après mise à jour (message de succès affiché)
    await expect(page).toHaveURL(/\/livreurs\/\d+\/edit$/, { timeout: 15_000 });

    // Vérifier dans la liste
    await page.goto('/livreurs');
    const updatedRow = await findRowByName(page, prenom);
    await expect(updatedRow).toBeVisible();
    await expect(updatedRow).toContainText(/adresse modifi/i);
    await expect(updatedRow).toContainText('Mamou');
});

// ─── Toggle statut ────────────────────────────────────────────────────────────

test('create livreur + toggle status → inactif in list', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Status${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createLivreurInApp(page, { prenom, nom, tel });

    const row = await findRowByName(page, prenom);
    await expect(row).toBeVisible();
    await expect(row).toContainText(/actif/i);

    // Modifier → désactiver
    await navigateToEdit(page, prenom);

    await page.locator('label[for="is_active"]').first().click();
    await page
        .locator('#livreur-form button[type="submit"]:visible')
        .first()
        .click();
    // Le contrôleur redirige vers edit après mise à jour (message de succès affiché)
    await expect(page).toHaveURL(/\/livreurs\/\d+\/edit$/, { timeout: 15_000 });

    await page.goto('/livreurs');
    await page.waitForLoadState('networkidle');
    const updated = await findRowByName(page, prenom);
    await expect(updated).toBeVisible();
    await expect(updated).toContainText(/inactif/i);
});

// ─── Validation requise ───────────────────────────────────────────────────────

test('create livreur without required fields → stays on create page', async ({
    page,
}) => {
    await login(page);
    await page.goto('/livreurs/create');

    // Soumettre sans rien remplir
    await page
        .locator('#livreur-form button[type="submit"]:visible')
        .first()
        .click();

    // Doit rester sur create (validation serveur ou client)
    await expect(page).toHaveURL(/\/livreurs\/create$/);
});
