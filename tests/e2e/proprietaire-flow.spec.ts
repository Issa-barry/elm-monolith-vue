import { expect, test } from '@playwright/test';
import { cleanupRowsByPrefix, escapeRegExp, getVisibleSearchInput, login, openRowActions, selectOptionFromCombobox } from './helpers';

const PREFIX = 'e2eproflow';

test.setTimeout(120_000);

function randomDigits(length: number): string {
    const max = 10 ** length;
    return `${Math.floor(Math.random() * max)}`.padStart(length, '0');
}

test.afterEach(async ({ browser }) => {
    const context = await browser.newContext();
    try {
        const p = await context.newPage();
        await cleanupRowsByPrefix(p, '/proprietaires', PREFIX);
    } catch (e) {
        console.warn('E2E cleanup warning (proprietaires):', e);
    } finally {
        await context.close().catch(() => undefined);
    }
});

// ─── Création ────────────────────────────────────────────────────────────────

test('create proprietaire with all fields → verify in list', async ({ page }) => {
    const uid    = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom    = `Flow${uid}`;
    const tel    = `6${randomDigits(8)}`;

    await login(page);
    await page.goto('/proprietaires/create');
    await expect(page).toHaveURL(/\/proprietaires\/create$/);

    await page.locator('#prenom').fill(prenom);
    await page.locator('#nom').fill(nom);

    // Pays (optionnel pour propriétaire)
    const paysCombo = page.locator('#proprietaire-form').getByRole('combobox').first();
    await selectOptionFromCombobox(page, paysCombo, /guinée$/i);

    await page.locator('#ville').fill('Conakry');
    await page.locator('#adresse').fill('Quartier Kaloum');
    await page.locator('#telephone').fill(tel);

    await page.locator('#proprietaire-form button[type="submit"]:visible').first().click();
    await expect(page).toHaveURL(/\/proprietaires$/);

    const search = getVisibleSearchInput(page);
    await search.fill(prenom);

    const row = page.locator('tbody tr', { hasText: new RegExp(escapeRegExp(prenom), 'i') }).first();
    await expect(row).toBeVisible();
});

// ─── Modification des champs de localisation ─────────────────────────────────

test('edit proprietaire → update pays / ville / adresse → data persists on edit page', async ({ page }) => {
    const uid    = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom    = `Edit${uid}`;
    const tel    = `6${randomDigits(8)}`;

    await login(page);

    // Création préalable
    await page.goto('/proprietaires/create');
    await page.locator('#prenom').fill(prenom);
    await page.locator('#nom').fill(nom);
    const paysCombo = page.locator('#proprietaire-form').getByRole('combobox').first();
    await selectOptionFromCombobox(page, paysCombo, /guinée$/i);
    await page.locator('#ville').fill('Conakry');
    await page.locator('#adresse').fill('Adresse initiale');
    await page.locator('#telephone').fill(tel);
    await page.locator('#proprietaire-form button[type="submit"]:visible').first().click();
    await expect(page).toHaveURL(/\/proprietaires$/);

    // Ouvrir l'édition
    const search = getVisibleSearchInput(page);
    await search.fill(prenom);
    const row = page.locator('tbody tr', { hasText: new RegExp(escapeRegExp(prenom), 'i') }).first();
    await openRowActions(row);
    await page.getByRole('menuitem', { name: /modifier/i }).first().click();
    await expect(page).toHaveURL(/\/proprietaires\/\d+\/edit$/);

    // Changer pays → Sénégal (met à jour l'indicatif et tronque le tel)
    const editPaysCombo = page.locator('#proprietaire-form').getByRole('combobox').first();
    await selectOptionFromCombobox(page, editPaysCombo, /sénégal/i);

    // Modifier ville et adresse
    await page.locator('#ville').clear();
    await page.locator('#ville').fill('Dakar');
    await page.locator('#adresse').clear();
    await page.locator('#adresse').fill('Plateau Dakar');

    // Remettre un téléphone valide pour le Sénégal (9 chiffres)
    await page.locator('#telephone').clear();
    await page.locator('#telephone').fill(`7${randomDigits(8)}`);

    await page.locator('#proprietaire-form button[type="submit"]:visible').first().click();

    // Doit rester sur la page d'édition (pas rediriger vers la liste)
    await expect(page).toHaveURL(/\/proprietaires\/\d+\/edit$/);

    // Bannière de succès visible
    await expect(page.locator('text=mis à jour')).toBeVisible();

    // Les champs reflètent les nouvelles valeurs
    await expect(page.locator('#ville')).toHaveValue('Dakar');
    await expect(page.locator('#adresse')).toHaveValue('Plateau Dakar');
});

// ─── Toggle statut ────────────────────────────────────────────────────────────

test('create proprietaire + toggle status → inactif in list', async ({ page }) => {
    const uid    = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom    = `Status${uid}`;
    const tel    = `6${randomDigits(8)}`;

    await login(page);

    await page.goto('/proprietaires/create');
    await page.locator('#prenom').fill(prenom);
    await page.locator('#nom').fill(nom);
    await page.locator('#telephone').fill(tel);
    await page.locator('#proprietaire-form button[type="submit"]:visible').first().click();
    await expect(page).toHaveURL(/\/proprietaires$/);

    const search = getVisibleSearchInput(page);
    await search.fill(prenom);
    const row = page.locator('tbody tr', { hasText: new RegExp(escapeRegExp(prenom), 'i') }).first();
    await expect(row).toBeVisible();

    await openRowActions(row);
    await page.getByRole('menuitem', { name: /modifier/i }).first().click();
    await expect(page).toHaveURL(/\/proprietaires\/\d+\/edit$/);

    await page.locator('label[for="is_active"]').first().click();
    await page.locator('#proprietaire-form button[type="submit"]:visible').first().click();

    // Reste sur edit + success
    await expect(page).toHaveURL(/\/proprietaires\/\d+\/edit$/);
    await expect(page.locator('text=mis à jour')).toBeVisible();

    // Vérifier dans la liste
    await page.goto('/proprietaires');
    const search2 = getVisibleSearchInput(page);
    await search2.fill(prenom);
    const updated = page.locator('tbody tr', { hasText: new RegExp(escapeRegExp(prenom), 'i') }).first();
    await expect(updated).toBeVisible();
    await expect(updated).toContainText(/inactif/i);
});

// ─── Validation requise ───────────────────────────────────────────────────────

test('create proprietaire without required fields → stays on create page', async ({ page }) => {
    await login(page);
    await page.goto('/proprietaires/create');

    await page.locator('#proprietaire-form button[type="submit"]:visible').first().click();

    await expect(page).toHaveURL(/\/proprietaires\/create$/);
});
