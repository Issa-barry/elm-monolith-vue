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

registerCleanup('/backoffice/clients', PREFIX);

async function createClientInApp(
    page: Parameters<typeof login>[0],
    params: {
        nomComplet: string;
        tel: string;
        adresse?: string;
        ville?: string;
    },
): Promise<void> {
    await page.goto('/backoffice/clients/create');
    await page.locator('#nom_complet').fill(params.nomComplet);

    const paysCombo = page
        .locator('#client-form')
        .getByRole('combobox')
        .first();
    await selectOptionFromCombobox(page, paysCombo, /guin(?!.*bissau)/i);

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

    await expect(page).toHaveURL(/\/clients\/[a-z0-9]+\/edit$/);
    await page.waitForLoadState('networkidle');
}

test('create client -> redirected to edit page with nom_complet title-cased', async ({
    page,
}) => {
    const uid = `${Date.now()}`.slice(-6);
    const nomComplet = `${PREFIX}${uid} Flow${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createClientInApp(page, {
        nomComplet,
        tel,
        ville: 'Conakry',
        adresse: 'Quartier Kaloum',
    });

    // Un seul champ "nom_complet" désormais (plus de split prenom/nom, cf.
    // ClientForm.vue), mais toujours normalisé en Title Case côté serveur
    // (PhoneHandlerTrait::ucTitle(), partagé avec Prestataire/Proprietaire) —
    // jamais enregistré tel quel.
    const expectedNomComplet = nomComplet
        .toLowerCase()
        .replaceAll(
            /(^|[^a-z])([a-z])/g,
            (_, sep, char) => sep + char.toUpperCase(),
        );
    await expect(page.locator('#nom_complet')).toHaveValue(expectedNomComplet);
});

test('create client with Guinea and empty ville -> defaults to Conakry', async ({
    page,
}) => {
    const uid = `${Date.now()}`.slice(-6);
    const nomComplet = `${PREFIX}${uid} Guinea${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await page.goto('/backoffice/clients/create');

    const paysCombo = page
        .locator('#client-form')
        .getByRole('combobox')
        .first();
    await selectOptionFromCombobox(page, paysCombo, /guin(?!.*bissau)/i);
    await page.locator('#ville').clear();

    await page.locator('#nom_complet').fill(nomComplet);
    await page.locator('#telephone').fill(tel);

    await page
        .locator('#client-form button[type="submit"]:visible')
        .first()
        .click();

    await expect(page).toHaveURL(/\/clients\/[a-z0-9]+\/edit$/);
    await expect(page.locator('#ville')).toHaveValue('Conakry');
});

test('edit client -> update ville and adresse -> persists', async ({
    page,
}) => {
    const uid = `${Date.now()}`.slice(-6);
    const nomComplet = `${PREFIX}${uid} Edit${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createClientInApp(page, {
        nomComplet,
        tel,
        ville: 'Conakry',
        adresse: 'Adresse initiale',
    });

    await page.locator('#ville').clear();
    await page.locator('#ville').fill('Kindia');
    await page.locator('#adresse').clear();
    await page.locator('#adresse').fill('Rue Principale');

    await page
        .locator('#client-form button[type="submit"]:visible')
        .first()
        .click();

    await expect(page).toHaveURL(/\/clients\/[a-z0-9]+\/edit$/);
    await expect(page.locator('#ville')).toHaveValue('Kindia');
    await expect(page.locator('#adresse')).toHaveValue('Rue Principale');
});

test('view client from list -> readonly form -> modifier redirects to edit', async ({
    page,
}) => {
    const uid = `${Date.now()}`.slice(-6);
    const nomComplet = `${PREFIX}${uid} View${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createClientInApp(page, {
        nomComplet,
        tel,
        ville: 'Conakry',
        adresse: 'Lecture seule',
    });

    await page.goto('/backoffice/clients');
    await page.waitForLoadState('networkidle');

    const row = await findRowByName(page, nomComplet);
    await openRowActions(row);
    await page
        .getByRole('menuitem', { name: /^Voir$/i })
        .first()
        .click();

    await expect(page).toHaveURL(/\/clients\/[a-z0-9]+$/);
    await expect(page.locator('#nom_complet')).toBeDisabled();

    const editTrigger = page
        .locator(
            'a:has-text("Modifier"):visible,button:has-text("Modifier"):visible',
        )
        .first();
    await expect(editTrigger).toBeVisible();
    await editTrigger.click();

    await expect(page).toHaveURL(/\/clients\/[a-z0-9]+\/edit$/);
    await expect(page.locator('#nom_complet')).toBeEnabled();
});

test('create client + toggle status -> inactif in list', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const nomComplet = `${PREFIX}${uid} Status${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createClientInApp(page, { nomComplet, tel, ville: 'Conakry' });

    await page.locator('label[for="is_active"]').first().click();
    await page
        .locator('#client-form button[type="submit"]:visible')
        .first()
        .click();

    await expect(page).toHaveURL(/\/clients\/[a-z0-9]+\/edit$/);

    await page.goto('/backoffice/clients');
    await page.waitForLoadState('networkidle');
    const updated = await findRowByName(page, nomComplet);
    await expect(updated).toBeVisible();
    await expect(updated).toContainText(/inactif/i);
});

test('create client with duplicate telephone -> stays on create with field error', async ({
    page,
}) => {
    const uid = `${Date.now()}`.slice(-6);
    const tel = `6${randomDigits(8)}`;
    const nomComplet1 = `${PREFIX}${uid}A Dup${uid}`;
    const nomComplet2 = `${PREFIX}${uid}B Dup2${uid}`;

    await login(page);
    await createClientInApp(page, { nomComplet: nomComplet1, tel });

    await page.goto('/backoffice/clients/create');
    await page.locator('#nom_complet').fill(nomComplet2);

    const paysCombo = page
        .locator('#client-form')
        .getByRole('combobox')
        .first();
    await selectOptionFromCombobox(page, paysCombo, /guin(?!.*bissau)/i);
    await page.locator('#telephone').fill(tel);

    await page
        .locator('#client-form button[type="submit"]:visible')
        .first()
        .click();

    await expect(page).toHaveURL(/\/clients\/create$/);
    await expect(page.locator('#telephone')).toHaveClass(/p-invalid/);
});

test('delete client -> no longer visible in list', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const nomComplet = `${PREFIX}${uid} Del${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createClientInApp(page, { nomComplet, tel });

    await page.goto('/backoffice/clients');
    await page.waitForLoadState('networkidle');

    const row = await findRowByName(page, nomComplet);
    await openRowActions(row);
    await page
        .getByRole('menuitem', { name: /supprimer/i })
        .first()
        .click();

    await page
        .getByRole('button', { name: /supprimer/i })
        .last()
        .click();
    await page.waitForLoadState('networkidle');

    const rows = page.locator('table tbody tr');
    const count = await rows.count();
    for (let i = 0; i < count; i++) {
        await expect(rows.nth(i)).not.toContainText(nomComplet);
    }
});

test('create client without required fields -> stays on create page', async ({
    page,
}) => {
    await login(page);
    await page.goto('/backoffice/clients/create');

    await page
        .locator('#client-form button[type="submit"]:visible')
        .first()
        .click();

    await expect(page).toHaveURL(/\/clients\/create$/);
});

test('create client -> switching nature from Revendeur to Distributeur resets cashback to Non by default', async ({
    page,
}) => {
    const uid = `${Date.now()}`.slice(-6);
    const nomComplet = `${PREFIX}${uid} CashbackDefault${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await page.goto('/backoffice/clients/create');
    await page.locator('#nom_complet').fill(nomComplet);

    const paysCombo = page
        .locator('#client-form')
        .getByRole('combobox')
        .first();
    await selectOptionFromCombobox(page, paysCombo, /guin(?!.*bissau)/i);
    await page.locator('#telephone').fill(tel);

    // Nature par défaut = Revendeur -> cashback verrouillé sur "Cashback actif", pas de choix
    // Oui/Non affiché.
    await expect(page.getByText(/cashback actif/i)).toBeVisible();

    // Changement de nature vers Distributeur : le cashback hérité (true, forcé pour Revendeur)
    // ne doit jamais se propager silencieusement -> "Non" doit être le choix actif par défaut.
    const typeCombo = page
        .locator('#client-form')
        .getByRole('combobox')
        .nth(1);
    await selectOptionFromCombobox(page, typeCombo, /^distributeur$/i);

    const nonButton = page.getByRole('button', { name: /^Non$/i });
    const ouiButton = page.getByRole('button', { name: /^Oui$/i });
    await expect(nonButton).toBeVisible();
    await expect(nonButton).toHaveClass(/bg-destructive/);
    await expect(ouiButton).not.toHaveClass(/bg-primary/);

    await page
        .locator('#client-form button[type="submit"]:visible')
        .first()
        .click();

    await expect(page).toHaveURL(/\/clients\/[a-z0-9]+\/edit$/);
    await expect(page.getByRole('button', { name: /^Non$/i })).toHaveClass(
        /bg-destructive/,
    );
});

test('stat cards reflect active search filter', async ({ page }) => {
    await login(page);
    await page.goto('/backoffice/clients');
    await page.waitForLoadState('networkidle');

    const totalCard = page
        .locator('p', { hasText: /total clients/i })
        .locator('xpath=following-sibling::p')
        .first();

    const totalBefore = parseInt((await totalCard.textContent()) ?? '0', 10);
    expect(totalBefore).toBeGreaterThan(0);

    const searchInput = page
        .locator(
            'input[placeholder*="recherch" i]:not([data-testid="global-search"]):visible',
        )
        .first();
    await searchInput.fill('ZZZZNO_MATCH_9999');
    await searchInput.press('Enter');
    await page.waitForLoadState('networkidle');

    await expect(totalCard).toHaveText('0', { timeout: 5_000 });

    const activeCard = page
        .locator('p', { hasText: /clients actifs/i })
        .locator('xpath=following-sibling::p')
        .first();
    await expect(activeCard).toHaveText('0');

    const inactiveCard = page
        .locator('p', { hasText: /clients inactifs/i })
        .locator('xpath=following-sibling::p')
        .first();
    await expect(inactiveCard).toHaveText('0');

    await searchInput.fill('');
    await searchInput.press('Enter');
    await page.waitForLoadState('networkidle');
    await expect(totalCard).not.toHaveText('0', { timeout: 5_000 });
});
