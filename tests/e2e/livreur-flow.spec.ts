import { expect, test } from '@playwright/test';
import { cleanupRowsByPrefix, escapeRegExp, getVisibleSearchInput, login, openRowActions, selectOptionFromCombobox } from './helpers';

const PREFIX = 'e2elivflow';

test.setTimeout(180_000);

function randomDigits(length: number): string {
    const max = 10 ** length;
    return `${Math.floor(Math.random() * max)}`.padStart(length, '0');
}

test.afterEach(async ({ browser }) => {
    try {
        const context = await browser.newContext();
        try {
            const p = await context.newPage();
            await cleanupRowsByPrefix(p, '/livreurs', PREFIX);
        } finally {
            await context.close().catch(() => undefined);
        }
    } catch (e) {
        console.warn('E2E cleanup warning (livreurs):', e);
    }
});

// ─── Création ────────────────────────────────────────────────────────────────

test('create livreur with all fields → verify in list', async ({ page }) => {
    const uid    = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom    = `Flow${uid}`;
    const tel    = `6${randomDigits(8)}`;

    await login(page);
    await page.goto('/livreurs/create');
    await expect(page).toHaveURL(/\/livreurs\/create$/);

    await page.locator('#prenom').fill(prenom);
    await page.locator('#nom').fill(nom);

    // Sélection du pays (Dropdown PrimeVue → role combobox)
    const paysCombo = page.locator('#livreur-form').getByRole('combobox').first();
    await selectOptionFromCombobox(page, paysCombo, /guinée$/i);

    await page.locator('#ville').fill('Conakry');
    await page.locator('#adresse').fill('Quartier Matam');
    await page.locator('#telephone').fill(tel);

    await page.locator('#livreur-form button[type="submit"]:visible').first().click();
    await expect(page).toHaveURL(/\/livreurs$/);

    const search = getVisibleSearchInput(page);
    await search.fill(prenom);

    const row = page.locator('tbody tr', { hasText: new RegExp(escapeRegExp(prenom), 'i') }).first();
    await expect(row).toBeVisible();

    // Localisation affichée : adresse + ville
    await expect(row).toContainText('Quartier Matam');
    await expect(row).toContainText('Conakry');
});

// ─── Modification des champs de localisation ─────────────────────────────────

test('edit livreur → update ville / adresse → data persists', async ({ page }) => {
    const uid    = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom    = `Edit${uid}`;
    const tel    = `6${randomDigits(8)}`;

    await login(page);

    // Création préalable
    await page.goto('/livreurs/create');
    await page.locator('#prenom').fill(prenom);
    await page.locator('#nom').fill(nom);
    const paysCombo = page.locator('#livreur-form').getByRole('combobox').first();
    await selectOptionFromCombobox(page, paysCombo, /guinée$/i);
    await page.locator('#ville').fill('Conakry');
    await page.locator('#adresse').fill('Adresse initiale');
    await page.locator('#telephone').fill(tel);
    await page.locator('#livreur-form button[type="submit"]:visible').first().click();
    await expect(page).toHaveURL(/\/livreurs$/);

    // Ouvrir l'édition
    const search = getVisibleSearchInput(page);
    await search.fill(prenom);
    const row = page.locator('tbody tr', { hasText: new RegExp(escapeRegExp(prenom), 'i') }).first();
    await openRowActions(row);
    await page.getByRole('menuitem', { name: /modifier/i }).first().click();
    await expect(page).toHaveURL(/\/livreurs\/\d+\/edit$/);

    // Modifier ville et adresse
    await page.locator('#ville').clear();
    await page.locator('#ville').fill('Mamou');
    await page.locator('#adresse').clear();
    await page.locator('#adresse').fill('Adresse modifiée');

    await page.locator('#livreur-form button[type="submit"]:visible').first().click();
    await expect(page).toHaveURL(/\/livreurs$/);

    // Vérifier dans la liste
    const search2 = getVisibleSearchInput(page);
    await search2.fill(prenom);
    const updatedRow = page.locator('tbody tr', { hasText: new RegExp(escapeRegExp(prenom), 'i') }).first();
    await expect(updatedRow).toBeVisible();
    await expect(updatedRow).toContainText(/adresse modifi/i);
    await expect(updatedRow).toContainText('Mamou');
});

// ─── Toggle statut ────────────────────────────────────────────────────────────

test('create livreur + toggle status → inactif in list', async ({ page }) => {
    const uid    = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom    = `Status${uid}`;
    const tel    = `6${randomDigits(8)}`;

    await login(page);

    await page.goto('/livreurs/create');
    await page.locator('#prenom').fill(prenom);
    await page.locator('#nom').fill(nom);
    const paysCombo = page.locator('#livreur-form').getByRole('combobox').first();
    await selectOptionFromCombobox(page, paysCombo, /guinée$/i);
    await page.locator('#ville').fill('Conakry');
    await page.locator('#telephone').fill(tel);
    await page.locator('#livreur-form button[type="submit"]:visible').first().click();
    await expect(page).toHaveURL(/\/livreurs$/);

    const search = getVisibleSearchInput(page);
    await search.fill(prenom);
    const row = page.locator('tbody tr', { hasText: new RegExp(escapeRegExp(prenom), 'i') }).first();
    await expect(row).toBeVisible();
    await expect(row).toContainText(/actif/i);

    // Modifier → désactiver
    await openRowActions(row);
    await page.getByRole('menuitem', { name: /modifier/i }).first().click();
    await expect(page).toHaveURL(/\/livreurs\/\d+\/edit$/);

    await page.locator('label[for="is_active"]').first().click();
    await page.locator('#livreur-form button[type="submit"]:visible').first().click();
    await expect(page).toHaveURL(/\/livreurs$/);

    const search2 = getVisibleSearchInput(page);
    await search2.fill(prenom);
    const updated = page.locator('tbody tr', { hasText: new RegExp(escapeRegExp(prenom), 'i') }).first();
    await expect(updated).toBeVisible();
    await expect(updated).toContainText(/inactif/i);
});

// ─── Validation requise ───────────────────────────────────────────────────────

test('create livreur without required fields → stays on create page', async ({ page }) => {
    await login(page);
    await page.goto('/livreurs/create');

    // Soumettre sans rien remplir
    await page.locator('#livreur-form button[type="submit"]:visible').first().click();

    // Doit rester sur create (validation serveur ou client)
    await expect(page).toHaveURL(/\/livreurs\/create$/);
});
