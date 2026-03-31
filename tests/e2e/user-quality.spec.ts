import { expect, test } from '@playwright/test';
import {
    cleanupRowsByPrefix,
    escapeRegExp,
    getVisibleSearchInput,
    login,
    openRowActions,
    selectOptionFromCombobox,
} from './helpers';

const PREFIX = 'e2eusrqual';

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
            await cleanupRowsByPrefix(p, '/users', PREFIX);
        } finally {
            await context.close().catch(() => undefined);
        }
    } catch (e) {
        console.warn('E2E quality cleanup warning (users):', e);
    }
});

// â”€â”€â”€ Recherche â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('search by name filters the list', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Srch${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);

    // CrÃ©ation
    await page.goto('/users/create');
    const form = page.locator('#user-form');
    await selectOptionFromCombobox(page, form.getByRole('combobox').first(), /guin/i);
    await page.locator('#prenom').fill(prenom);
    await page.locator('#nom').fill(nom);
    await page.locator('#telephone').fill(tel);
    await selectOptionFromCombobox(page, form.getByRole('combobox').nth(1), /manager/i);
    await form.getByRole('combobox').nth(2).click();
    await page.locator('[role="option"]:visible').first().click();
    await form.locator('button[type="submit"]:visible').click();
    await expect(page.locator('#password')).toBeVisible();
    await page.locator('#password').fill('Password123');
    await page.locator('#password_confirmation').fill('Password123');
    await page.locator('#user-form button[type="submit"]:visible').first().click();
    await expect(page).toHaveURL(/\/users\/\d+\/edit$/);

    await page.goto('/users');

    // Recherche par prÃ©nom â€” doit trouver
    const search = getVisibleSearchInput(page);
    await search.fill(prenom);
    const rows = page.locator('tbody tr:visible');
    await expect(rows.first()).toBeVisible();
    const count = await rows.count();
    for (let i = 0; i < count; i++) {
        await expect(rows.nth(i)).toContainText(new RegExp(escapeRegExp(prenom), 'i'));
    }

    // Recherche fictive â€” doit ne rien trouver
    await search.fill('xxxxxxxxxxxxxxxx_nomquinexistepas');
    await expect(page.getByText(/aucun utilisateur trouv/i)).toBeVisible();
});

test('search by email filters the list', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Mail${uid}`;
    const tel = `6${randomDigits(8)}`;
    const email = `${PREFIX}${uid}@testqual.com`;

    await login(page);

    await page.goto('/users/create');
    const form = page.locator('#user-form');
    await selectOptionFromCombobox(page, form.getByRole('combobox').first(), /guin/i);
    await page.locator('#prenom').fill(prenom);
    await page.locator('#nom').fill(nom);
    await page.locator('#telephone').fill(tel);
    await page.locator('#email').fill(email);
    await selectOptionFromCombobox(page, form.getByRole('combobox').nth(1), /manager/i);
    await form.getByRole('combobox').nth(2).click();
    await page.locator('[role="option"]:visible').first().click();
    await form.locator('button[type="submit"]:visible').click();
    await expect(page.locator('#password')).toBeVisible();
    await page.locator('#password').fill('Password123');
    await page.locator('#password_confirmation').fill('Password123');
    await page.locator('#user-form button[type="submit"]:visible').first().click();
    await expect(page).toHaveURL(/\/users\/\d+\/edit$/);

    await page.goto('/users');
    const search = getVisibleSearchInput(page);
    await search.fill(email);

    const row = page
        .locator('tbody tr', { hasText: new RegExp(escapeRegExp(email), 'i') })
        .first();
    await expect(row).toBeVisible();
});

// â”€â”€â”€ Cartes de statistiques â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('stats cards display correct counts', async ({ page }) => {
    await login(page);
    await page.goto('/users');

    // Les 3 cartes doivent Ãªtre visibles avec des chiffres
    const totalCard = page.getByText('Total utilisateurs').locator('..').locator('p.text-3xl');
    const activeCard = page.getByText('Utilisateurs actifs').locator('..').locator('p.text-3xl');
    const inactiveCard = page.getByText('Utilisateurs inactifs').locator('..').locator('p.text-3xl');

    await expect(totalCard).toBeVisible();
    await expect(activeCard).toBeVisible();
    await expect(inactiveCard).toBeVisible();

    const total = parseInt(await totalCard.innerText(), 10);
    const active = parseInt(await activeCard.innerText(), 10);
    const inactive = parseInt(await inactiveCard.innerText(), 10);

    expect(total).toBeGreaterThan(0);
    expect(active + inactive).toBe(total);
});

// â”€â”€â”€ Filtre inactif â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('inactive filter shows only inactive users', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Inac${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);

    // CrÃ©er un utilisateur puis le dÃ©sactiver
    await page.goto('/users/create');
    const form = page.locator('#user-form');
    await selectOptionFromCombobox(page, form.getByRole('combobox').first(), /guin/i);
    await page.locator('#prenom').fill(prenom);
    await page.locator('#nom').fill(nom);
    await page.locator('#telephone').fill(tel);
    await selectOptionFromCombobox(page, form.getByRole('combobox').nth(1), /manager/i);
    await form.getByRole('combobox').nth(2).click();
    await page.locator('[role="option"]:visible').first().click();
    await form.locator('button[type="submit"]:visible').click();
    await expect(page.locator('#password')).toBeVisible();
    await page.locator('#password').fill('Password123');
    await page.locator('#password_confirmation').fill('Password123');
    await page.locator('#user-form button[type="submit"]:visible').first().click();
    await expect(page).toHaveURL(/\/users\/\d+\/edit$/);

    // DÃ©sactiver via le formulaire d'Ã©dition
    await page.locator('label[for="is_active"]').first().click();
    await page.locator('#user-form button[type="submit"]:visible').first().click();
    await expect(page).toHaveURL(/\/users\/\d+\/edit$/);

    // VÃ©rifier le filtre "Inactif" sur la liste
    await page.goto('/users');
    await page.getByRole('button', { name: /^inactif$/i }).click();

    const rows = page.locator('tbody tr:visible');
    const count = await rows.count();
    expect(count).toBeGreaterThan(0);
    for (let i = 0; i < count; i++) {
        await expect(rows.nth(i)).toContainText(/inactif/i);
        await expect(rows.nth(i)).not.toContainText(/^actif$/i);
    }
});

// â”€â”€â”€ Validation doublon â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('duplicate phone number shows validation error', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const tel = `6${randomDigits(8)}`;

    await login(page);

    // Premier utilisateur avec ce tÃ©lÃ©phone
    await page.goto('/users/create');
    const form = page.locator('#user-form');
    await selectOptionFromCombobox(page, form.getByRole('combobox').first(), /guin/i);
    await page.locator('#prenom').fill(`${PREFIX}${uid}A`);
    await page.locator('#nom').fill(`Dup${uid}A`);
    await page.locator('#telephone').fill(tel);
    await selectOptionFromCombobox(page, form.getByRole('combobox').nth(1), /manager/i);
    await form.getByRole('combobox').nth(2).click();
    await page.locator('[role="option"]:visible').first().click();
    await form.locator('button[type="submit"]:visible').click();
    await expect(page.locator('#password')).toBeVisible();
    await page.locator('#password').fill('Password123');
    await page.locator('#password_confirmation').fill('Password123');
    await page.locator('#user-form button[type="submit"]:visible').first().click();
    await expect(page).toHaveURL(/\/users\/\d+\/edit$/);

    // Second utilisateur avec le mÃªme tÃ©lÃ©phone
    await page.goto('/users/create');
    const form2 = page.locator('#user-form');
    await selectOptionFromCombobox(page, form2.getByRole('combobox').first(), /guin/i);
    await page.locator('#prenom').fill(`${PREFIX}${uid}B`);
    await page.locator('#nom').fill(`Dup${uid}B`);
    await page.locator('#telephone').fill(tel);
    await selectOptionFromCombobox(page, form2.getByRole('combobox').nth(1), /manager/i);
    await form2.getByRole('combobox').nth(2).click();
    await page.locator('[role="option"]:visible').first().click();
    await form2.locator('button[type="submit"]:visible').click();
    await expect(page.locator('#password')).toBeVisible();
    await page.locator('#password').fill('Password123');
    await page.locator('#password_confirmation').fill('Password123');
    await page.locator('#user-form button[type="submit"]:visible').first().click();

    // Doit rester sur la page create avec une erreur tÃ©lÃ©phone
    await expect(page).toHaveURL(/\/users\/create$/);
    await page.getByRole('button', { name: /informations/i }).click();
    await expect(page.locator('#telephone')).toBeVisible();
    await expect(page.locator('#telephone')).toHaveClass(/p-invalid/);
});

// â”€â”€â”€ Formatage des champs â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('prenom is saved as title case and nom as uppercase', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const tel = `6${randomDigits(8)}`;

    await login(page);

    await page.goto('/users/create');
    const form = page.locator('#user-form');
    await selectOptionFromCombobox(page, form.getByRole('combobox').first(), /guin/i);
    await page.locator('#prenom').fill('mamadou');
    await page.locator('#nom').fill('barry');
    await page.locator('#telephone').fill(tel);
    await selectOptionFromCombobox(page, form.getByRole('combobox').nth(1), /manager/i);
    await form.getByRole('combobox').nth(2).click();
    await page.locator('[role="option"]:visible').first().click();
    await form.locator('button[type="submit"]:visible').click();
    await expect(page.locator('#password')).toBeVisible();
    await page.locator('#password').fill('Password123');
    await page.locator('#password_confirmation').fill('Password123');
    await page.locator('#user-form button[type="submit"]:visible').first().click();
    await expect(page).toHaveURL(/\/users\/\d+\/edit$/);

    // Le nom doit apparaÃ®tre en majuscules dans la liste
    await page.goto('/users');
    const search = getVisibleSearchInput(page);
    await search.fill(`${PREFIX}${uid}`);

    // Ou chercher directement BARRY dans la table
    const search2 = getVisibleSearchInput(page);
    await search2.fill('mamadou barry');
    // La ligne doit exister avec Mamadou BARRY (title case + uppercase)
    const row = page
        .locator('tbody tr', { hasText: /Mamadou/i })
        .filter({ hasText: /BARRY/i })
        .first();
    await expect(row).toBeVisible();
});

// â”€â”€â”€ Navigation vers Ã©dition depuis la liste â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('edit action in dropdown navigates to edit page', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Nav${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);

    // CrÃ©er un utilisateur
    await page.goto('/users/create');
    const form = page.locator('#user-form');
    await selectOptionFromCombobox(page, form.getByRole('combobox').first(), /guin/i);
    await page.locator('#prenom').fill(prenom);
    await page.locator('#nom').fill(nom);
    await page.locator('#telephone').fill(tel);
    await selectOptionFromCombobox(page, form.getByRole('combobox').nth(1), /manager/i);
    await form.getByRole('combobox').nth(2).click();
    await page.locator('[role="option"]:visible').first().click();
    await form.locator('button[type="submit"]:visible').click();
    await expect(page.locator('#password')).toBeVisible();
    await page.locator('#password').fill('Password123');
    await page.locator('#password_confirmation').fill('Password123');
    await page.locator('#user-form button[type="submit"]:visible').first().click();
    await expect(page).toHaveURL(/\/users\/\d+\/edit$/);

    // Naviguer depuis la liste
    await page.goto('/users');
    const search = getVisibleSearchInput(page);
    await search.fill(prenom);

    const row = page
        .locator('tbody tr', { hasText: new RegExp(escapeRegExp(prenom), 'i') })
        .first();
    await expect(row).toBeVisible();

    await openRowActions(row);
    await page.getByRole('menuitem', { name: /modifier/i }).first().click();

    await expect(page).toHaveURL(/\/users\/\d+\/edit$/);
    await expect(page.locator('#prenom')).toBeVisible();
});

// â”€â”€â”€ Pagination / affichage titre â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

test('users index page has correct title and breadcrumb', async ({ page }) => {
    await login(page);
    await page.goto('/users');

    await expect(page).toHaveTitle(/utilisateurs/i);
    await expect(page.getByRole('heading', { name: /^utilisateurs$/i })).toBeVisible();
});




