import { expect, test } from '@playwright/test';
import {
    findRowByName,
    login,
    openRowActions,
    randomDigits,
    registerCleanup,
    selectOptionFromCombobox,
} from './helpers';

const PREFIX = 'e2ecliflow';

test.setTimeout(180_000);

registerCleanup('/clients', PREFIX);

async function navigateToEdit(
    page: Parameters<typeof login>[0],
    name: string,
): Promise<void> {
    const row = await findRowByName(page, name);
    await openRowActions(row);
    await page.getByRole('menuitem', { name: /modifier/i }).first().click();
    await expect(page).toHaveURL(/\/clients\/\d+\/edit$/);
}

async function createClientInApp(
    page: Parameters<typeof login>[0],
    params: { prenom: string; nom: string; tel: string; adresse?: string; ville?: string },
): Promise<void> {
    await page.goto('/clients/create');
    await page.locator('#prenom').fill(params.prenom);
    await page.locator('#nom').fill(params.nom);
    if (params.ville || params.adresse) {
        const paysCombo = page
            .locator('#client-form')
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
        .locator('#client-form button[type="submit"]:visible')
        .first()
        .click();
    await expect(page).toHaveURL(/\/clients$/);
}

// ─── Création ────────────────────────────────────────────────────────────────

test('create client with all fields → verify in list', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Flow${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createClientInApp(page, {
        prenom,
        nom,
        tel,
        ville: 'Conakry',
        adresse: 'Quartier Kaloum',
    });

    const row = await findRowByName(page, prenom);
    await expect(row).toBeVisible();
});

// ─── Pays = Guinée → ville = Conakry ─────────────────────────────────────────

test('create client with Guinée → ville defaults to Conakry', async ({
    page,
}) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Guinea${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await page.goto('/clients/create');

    // Sélectionner Guinée sans renseigner la ville
    const paysCombo = page
        .locator('#client-form')
        .getByRole('combobox')
        .first();
    await selectOptionFromCombobox(page, paysCombo, /guinée$/i);
    // Vider le champ ville s'il a été prérempli
    await page.locator('#ville').clear();

    await page.locator('#prenom').fill(prenom);
    await page.locator('#nom').fill(nom);
    await page.locator('#telephone').fill(tel);

    await page
        .locator('#client-form button[type="submit"]:visible')
        .first()
        .click();
    await expect(page).toHaveURL(/\/clients$/);

    // Vérifier en éditant que Conakry a été appliqué
    await navigateToEdit(page, prenom);
    await expect(page.locator('#ville')).toHaveValue('Conakry');
});

// ─── Modification ─────────────────────────────────────────────────────────────

test('edit client → update ville / adresse → data persists on edit page', async ({
    page,
}) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Edit${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createClientInApp(page, {
        prenom,
        nom,
        tel,
        ville: 'Conakry',
        adresse: 'Adresse initiale',
    });

    await navigateToEdit(page, prenom);

    // Modifier ville et adresse
    await page.locator('#ville').clear();
    await page.locator('#ville').fill('Kindia');
    await page.locator('#adresse').clear();
    await page.locator('#adresse').fill('Rue Principale');

    await page
        .locator('#client-form button[type="submit"]:visible')
        .first()
        .click();

    // Doit rester sur la page d'édition
    await expect(page).toHaveURL(/\/clients\/\d+\/edit$/);

    // Bannière de succès visible
    await expect(page.locator('text=mis à jour')).toBeVisible();

    // Les champs reflètent les nouvelles valeurs
    await expect(page.locator('#ville')).toHaveValue('Kindia');
    await expect(page.locator('#adresse')).toHaveValue('Rue Principale');
});

// ─── Toggle statut actif ──────────────────────────────────────────────────────

test('create client + toggle status → inactif in list', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Status${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createClientInApp(page, { prenom, nom, tel, ville: 'Conakry' });

    const row = await findRowByName(page, prenom);
    await expect(row).toBeVisible();
    await navigateToEdit(page, prenom);

    await page.locator('label[for="is_active"]').first().click();
    await page
        .locator('#client-form button[type="submit"]:visible')
        .first()
        .click();

    await expect(page).toHaveURL(/\/clients\/\d+\/edit$/);
    await expect(page.locator('text=mis à jour')).toBeVisible();

    // Vérifier dans la liste
    await page.goto('/clients');
    await page.waitForLoadState('networkidle');
    const updated = await findRowByName(page, prenom);
    await expect(updated).toBeVisible();
    await expect(updated).toContainText(/inactif/i);
});

// ─── Unicité téléphone ────────────────────────────────────────────────────────

test('create client with duplicate telephone → uniqueness error', async ({
    page,
}) => {
    const uid = `${Date.now()}`.slice(-6);
    const tel = `6${randomDigits(8)}`;
    const prenom1 = `${PREFIX}${uid}A`;
    const prenom2 = `${PREFIX}${uid}B`;

    await login(page);

    // Créer le premier client
    await createClientInApp(page, { prenom: prenom1, nom: `Dup${uid}`, tel });

    // Tenter de créer un second client avec le même numéro
    await page.goto('/clients/create');
    await page.locator('#prenom').fill(prenom2);
    await page.locator('#nom').fill(`Dup2${uid}`);
    await page.locator('#telephone').fill(tel);
    await page
        .locator('#client-form button[type="submit"]:visible')
        .first()
        .click();

    // Doit rester sur la page de création avec une erreur
    await expect(page).toHaveURL(/\/clients\/create$/);
    await expect(
        page.locator('text=déjà utilisé').first(),
    ).toBeVisible();
});

// ─── Archivage ────────────────────────────────────────────────────────────────

test('delete client → no longer visible in list', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Del${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createClientInApp(page, { prenom, nom, tel });

    // Trouver et supprimer
    const row = await findRowByName(page, prenom);
    await openRowActions(row);
    await page.getByRole('menuitem', { name: /supprimer/i }).first().click();

    // Confirmer la suppression dans la boîte de dialogue
    await page.getByRole('button', { name: /supprimer/i }).last().click();

    await page.waitForLoadState('networkidle');

    // Le client ne doit plus apparaître
    const rows = page.locator('table tbody tr');
    const count = await rows.count();
    for (let i = 0; i < count; i++) {
        await expect(rows.nth(i)).not.toContainText(prenom);
    }
});

// ─── Validation requise ───────────────────────────────────────────────────────

test('create client without required fields → stays on create page', async ({
    page,
}) => {
    await login(page);
    await page.goto('/clients/create');

    await page
        .locator('#client-form button[type="submit"]:visible')
        .first()
        .click();

    await expect(page).toHaveURL(/\/clients\/create$/);
});
