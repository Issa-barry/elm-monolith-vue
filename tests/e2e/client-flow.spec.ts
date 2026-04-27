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

async function createClientInApp(
    page: Parameters<typeof login>[0],
    params: {
        prenom: string;
        nom: string;
        tel: string;
        adresse?: string;
        ville?: string;
    },
): Promise<void> {
    await page.goto('/clients/create');
    await page.locator('#prenom').fill(params.prenom);
    await page.locator('#nom').fill(params.nom);

    const paysCombo = page.locator('#client-form').getByRole('combobox').first();
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

test('create client -> redirected to edit page with expected prenom casing', async ({
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

    const expectedPrenom = prenom
        .toLowerCase()
        .replaceAll(
            /(^|[^a-z])([a-z])/g,
            (_, sep, char) => sep + char.toUpperCase(),
        );
    await expect(page.locator('#prenom')).toHaveValue(expectedPrenom);
});

test('create client with Guinea and empty ville -> defaults to Conakry', async ({
    page,
}) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Guinea${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await page.goto('/clients/create');

    const paysCombo = page.locator('#client-form').getByRole('combobox').first();
    await selectOptionFromCombobox(page, paysCombo, /guin(?!.*bissau)/i);
    await page.locator('#ville').clear();

    await page.locator('#prenom').fill(prenom);
    await page.locator('#nom').fill(nom);
    await page.locator('#telephone').fill(tel);

    await page
        .locator('#client-form button[type="submit"]:visible')
        .first()
        .click();

    await expect(page).toHaveURL(/\/clients\/[a-z0-9]+\/edit$/);
    await expect(page.locator('#ville')).toHaveValue('Conakry');
});

test('edit client -> update ville and adresse -> persists', async ({ page }) => {
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
    const prenom = `${PREFIX}${uid}`;
    const nom = `View${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createClientInApp(page, {
        prenom,
        nom,
        tel,
        ville: 'Conakry',
        adresse: 'Lecture seule',
    });

    await page.goto('/clients');
    await page.waitForLoadState('networkidle');

    const row = await findRowByName(page, prenom);
    await openRowActions(row);
    await page
        .getByRole('menuitem', { name: /^Voir$/i })
        .first()
        .click();

    await expect(page).toHaveURL(/\/clients\/[a-z0-9]+$/);
    await expect(page.locator('#prenom')).toBeDisabled();
    await expect(page.locator('#nom')).toBeDisabled();

    const editTrigger = page
        .locator(
            'a:has-text("Modifier"):visible,button:has-text("Modifier"):visible',
        )
        .first();
    await expect(editTrigger).toBeVisible();
    await editTrigger.click();

    await expect(page).toHaveURL(/\/clients\/[a-z0-9]+\/edit$/);
    await expect(page.locator('#prenom')).toBeEnabled();
});

test('create client + toggle status -> inactif in list', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Status${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createClientInApp(page, { prenom, nom, tel, ville: 'Conakry' });

    await page.locator('label[for="is_active"]').first().click();
    await page
        .locator('#client-form button[type="submit"]:visible')
        .first()
        .click();

    await expect(page).toHaveURL(/\/clients\/[a-z0-9]+\/edit$/);

    await page.goto('/clients');
    await page.waitForLoadState('networkidle');
    const updated = await findRowByName(page, prenom);
    await expect(updated).toBeVisible();
    await expect(updated).toContainText(/inactif/i);
});

test('create client with duplicate telephone -> stays on create with field error', async ({
    page,
}) => {
    const uid = `${Date.now()}`.slice(-6);
    const tel = `6${randomDigits(8)}`;
    const prenom1 = `${PREFIX}${uid}A`;
    const prenom2 = `${PREFIX}${uid}B`;

    await login(page);
    await createClientInApp(page, { prenom: prenom1, nom: `Dup${uid}`, tel });

    await page.goto('/clients/create');
    await page.locator('#prenom').fill(prenom2);
    await page.locator('#nom').fill(`Dup2${uid}`);

    const paysCombo = page.locator('#client-form').getByRole('combobox').first();
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
    const prenom = `${PREFIX}${uid}`;
    const nom = `Del${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createClientInApp(page, { prenom, nom, tel });

    await page.goto('/clients');
    await page.waitForLoadState('networkidle');

    const row = await findRowByName(page, prenom);
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
        await expect(rows.nth(i)).not.toContainText(prenom);
    }
});

test('create client without required fields -> stays on create page', async ({
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
