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

/**
 * Crée un client depuis le back-office.
 * Après création la page redirige vers `/clients/{id}/edit`.
 */
async function createClientInApp(
    page: Parameters<typeof login>[0],
    params: { prenom: string; nom: string; tel: string; adresse?: string; ville?: string },
): Promise<void> {
    await page.goto('/clients/create');
    await page.locator('#prenom').fill(params.prenom);
    await page.locator('#nom').fill(params.nom);

    // Sélectionner le pays (Guinée par défaut)
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

    await page.locator('#telephone').fill(params.tel);
    await page
        .locator('#client-form button[type="submit"]:visible')
        .first()
        .click();

    // Après création → redirigé vers la page edit du client
    await expect(page).toHaveURL(/\/clients\/\d+\/edit$/);
}

// ─── Création → redirection vers edit ────────────────────────────────────────

test('create client → redirected to edit page with success message', async ({
    page,
}) => {
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

    // Bannière de succès sur la page edit
    // Timeout étendu : au premier run en CI le serveur PHP est froid et peut
    // prendre plusieurs secondes supplémentaires pour répondre.
    await expect(page.locator('text=créé avec succès')).toBeVisible({
        timeout: 30_000,
    });

    // Le backend applique ucTitle sur le prénom (MB_CASE_TITLE après mb_strtolower) :
    // chaque lettre qui suit un non-lettre est mise en majuscule.
    // Ex: "e2ecliflow576187" → "E2Ecliflow576187"
    const expectedPrenom = prenom
        .toLowerCase()
        .replaceAll(/(^|[^a-z])([a-z])/g, (_, sep, char) => sep + char.toUpperCase());
    await expect(page.locator('#prenom')).toHaveValue(expectedPrenom);
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
    await page.locator('#ville').clear();

    await page.locator('#prenom').fill(prenom);
    await page.locator('#nom').fill(nom);
    await page.locator('#telephone').fill(tel);

    await page
        .locator('#client-form button[type="submit"]:visible')
        .first()
        .click();

    // Redirigé vers edit
    await expect(page).toHaveURL(/\/clients\/\d+\/edit$/);

    // Conakry a été appliqué par le backend
    await expect(page.locator('#ville')).toHaveValue('Conakry');
});

// ─── Modification → reste sur edit + message succès ──────────────────────────

test('edit client → update ville / adresse → data persists + success message', async ({
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

    // Modifier ville et adresse depuis la page edit (déjà là après create)
    await page.locator('#ville').clear();
    await page.locator('#ville').fill('Kindia');
    await page.locator('#adresse').clear();
    await page.locator('#adresse').fill('Rue Principale');

    await page
        .locator('#client-form button[type="submit"]:visible')
        .first()
        .click();

    // Reste sur la page edit
    await expect(page).toHaveURL(/\/clients\/\d+\/edit$/);

    // Bannière de succès
    await expect(page.locator('text=mis à jour')).toBeVisible();

    // Données persistées
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

    // Déjà sur la page edit — décocher is_active
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

test('create client with duplicate telephone → uniqueness error stays on create', async ({
    page,
}) => {
    const uid = `${Date.now()}`.slice(-6);
    const tel = `6${randomDigits(8)}`;
    const prenom1 = `${PREFIX}${uid}A`;
    const prenom2 = `${PREFIX}${uid}B`;

    await login(page);

    // Créer le premier client (redirige vers edit)
    await createClientInApp(page, { prenom: prenom1, nom: `Dup${uid}`, tel });

    // Tenter de créer un second client avec le même numéro
    await page.goto('/clients/create');
    await page.locator('#prenom').fill(prenom2);
    await page.locator('#nom').fill(`Dup2${uid}`);

    const paysCombo = page
        .locator('#client-form')
        .getByRole('combobox')
        .first();
    await selectOptionFromCombobox(page, paysCombo, /guinée$/i);
    await page.locator('#telephone').fill(tel);

    await page
        .locator('#client-form button[type="submit"]:visible')
        .first()
        .click();

    // Doit rester sur la page create avec une erreur d'unicité
    await expect(page).toHaveURL(/\/clients\/create$/);
    await expect(page.locator('text=déjà utilisé').first()).toBeVisible();
});

// ─── Archivage ────────────────────────────────────────────────────────────────

test('delete client → no longer visible in list', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Del${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createClientInApp(page, { prenom, nom, tel });

    // Aller dans la liste pour trouver et supprimer
    await page.goto('/clients');
    await page.waitForLoadState('networkidle');

    const row = await findRowByName(page, prenom);
    await openRowActions(row);
    await page.getByRole('menuitem', { name: /supprimer/i }).first().click();

    // Confirmer dans la boîte de dialogue PrimeVue
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
