import { expect, test } from '@playwright/test';
import {
    findRowByName,
    login,
    openRowActions,
    randomDigits,
    registerCleanup,
    selectOptionFromCombobox,
} from './helpers';

const PREFIX = 'e2eproflow';

test.setTimeout(180_000);

registerCleanup('/proprietaires', PREFIX);

async function navigateToEdit(
    page: Parameters<typeof login>[0],
    name: string,
): Promise<void> {
    const row = await findRowByName(page, name);
    await openRowActions(row);
    await page.getByRole('menuitem', { name: /modifier/i }).first().click();
    await expect(page).toHaveURL(/\/proprietaires\/\d+\/edit$/);
}

async function createProprietaireInApp(
    page: Parameters<typeof login>[0],
    params: { prenom: string; nom: string; tel: string; adresse?: string; ville?: string },
): Promise<void> {
    await page.goto('/proprietaires/create');
    await page.locator('#prenom').fill(params.prenom);
    await page.locator('#nom').fill(params.nom);
    if (params.ville || params.adresse) {
        const paysCombo = page
            .locator('#proprietaire-form')
            .getByRole('combobox')
            .first();
        await selectOptionFromCombobox(page, paysCombo, /guinée$/i);
        if (params.ville) {
            await page.locator('#ville').fill(params.ville);
        }
        if (params.adresse) {
            await page.locator('#adresse').fill(params.adresse);
        }
    }
    await page.locator('#telephone').fill(params.tel);
    await page
        .locator('#proprietaire-form button[type="submit"]:visible')
        .first()
        .click();
    await expect(page).toHaveURL(/\/proprietaires$/);
}

// ─── Création ────────────────────────────────────────────────────────────────

test('create proprietaire with all fields → verify in list', async ({
    page,
}) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Flow${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createProprietaireInApp(page, {
        prenom, nom, tel,
        ville: 'Conakry',
        adresse: 'Quartier Kaloum',
    });

    const row = await findRowByName(page, prenom);
    await expect(row).toBeVisible();
});

// ─── Modification des champs de localisation ─────────────────────────────────

test('edit proprietaire → update pays / ville / adresse → data persists on edit page', async ({
    page,
}) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Edit${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createProprietaireInApp(page, {
        prenom, nom, tel,
        ville: 'Conakry',
        adresse: 'Adresse initiale',
    });

    // Ouvrir l'édition
    await navigateToEdit(page, prenom);

    // Changer pays → Sénégal (met à jour l'indicatif et tronque le tel)
    const editPaysCombo = page
        .locator('#proprietaire-form')
        .getByRole('combobox')
        .first();
    await selectOptionFromCombobox(page, editPaysCombo, /sénégal/i);

    // Modifier ville et adresse
    await page.locator('#ville').clear();
    await page.locator('#ville').fill('Dakar');
    await page.locator('#adresse').clear();
    await page.locator('#adresse').fill('Plateau Dakar');

    // Remettre un téléphone valide pour le Sénégal (9 chiffres)
    await page.locator('#telephone').clear();
    await page.locator('#telephone').fill(`7${randomDigits(8)}`);

    await page
        .locator('#proprietaire-form button[type="submit"]:visible')
        .first()
        .click();

    // Doit rester sur la page d'édition (pas rediriger vers la liste)
    await expect(page).toHaveURL(/\/proprietaires\/\d+\/edit$/);

    // Bannière de succès visible
    await expect(page.locator('text=mis à jour')).toBeVisible();

    // Les champs reflètent les nouvelles valeurs
    await expect(page.locator('#ville')).toHaveValue('Dakar');
    await expect(page.locator('#adresse')).toHaveValue('Plateau Dakar');
});

// ─── Toggle statut ────────────────────────────────────────────────────────────

test('create proprietaire + toggle status → inactif in list', async ({
    page,
}) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Status${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createProprietaireInApp(page, { prenom, nom, tel });

    const row = await findRowByName(page, prenom);
    await expect(row).toBeVisible();
    await navigateToEdit(page, prenom);

    await page.locator('label[for="is_active"]').first().click();
    await page
        .locator('#proprietaire-form button[type="submit"]:visible')
        .first()
        .click();

    // Reste sur edit + success
    await expect(page).toHaveURL(/\/proprietaires\/\d+\/edit$/);
    await expect(page.locator('text=mis à jour')).toBeVisible();

    // Vérifier dans la liste
    await page.goto('/proprietaires');
    const updated = await findRowByName(page, prenom);
    await expect(updated).toBeVisible();
    await expect(updated).toContainText(/inactif/i);
});

// ─── Validation requise ───────────────────────────────────────────────────────

test('create proprietaire without required fields → stays on create page', async ({
    page,
}) => {
    await login(page);
    await page.goto('/proprietaires/create');

    await page
        .locator('#proprietaire-form button[type="submit"]:visible')
        .first()
        .click();

    await expect(page).toHaveURL(/\/proprietaires\/create$/);
});
