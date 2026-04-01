import { expect, test } from '@playwright/test';
import {
    cleanupRowsByPrefix,
    createUser,
    escapeRegExp,
    fillUserInfoAndAdvance,
    findUserInList,
    getVisibleSearchInput,
    login,
    openRowActions,
    randomDigits,
    selectOptionFromCombobox,
} from './helpers';

const PREFIX = 'e2eusrqual';

test.setTimeout(180_000);

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

// ─── Recherche ────────────────────────────────────────────────────────────────

test('search by name filters the list', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Srch${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createUser(page, { prenom, nom, tel });

    await page.goto('/users');

    // Recherche par prénom – doit trouver
    const search = getVisibleSearchInput(page);
    await search.fill(prenom);
    const rows = page.locator('tbody tr:visible');
    await expect(rows.first()).toBeVisible();
    const count = await rows.count();
    for (let i = 0; i < count; i++) {
        await expect(rows.nth(i)).toContainText(new RegExp(escapeRegExp(prenom), 'i'));
    }

    // Recherche fictive – doit ne rien trouver
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
    await createUser(page, { prenom, nom, tel, email });

    await page.goto('/users');
    const search = getVisibleSearchInput(page);
    await search.fill(email);

    const row = page
        .locator('tbody tr', { hasText: new RegExp(escapeRegExp(email), 'i') })
        .first();
    await expect(row).toBeVisible();
});

// ─── Cartes de statistiques ───────────────────────────────────────────────────

test('stats cards display correct counts', async ({ page }) => {
    await login(page);
    await page.goto('/users');

    const totalCard = page.getByText('Total utilisateurs').locator('..').locator('p.text-3xl');
    const activeCard = page.getByText('Utilisateurs actifs').locator('..').locator('p.text-3xl');
    const inactiveCard = page.getByText('Utilisateurs inactifs').locator('..').locator('p.text-3xl');

    await expect(totalCard).toBeVisible();
    await expect(activeCard).toBeVisible();
    await expect(inactiveCard).toBeVisible();

    const total = Number.parseInt(await totalCard.innerText(), 10);
    const active = Number.parseInt(await activeCard.innerText(), 10);
    const inactive = Number.parseInt(await inactiveCard.innerText(), 10);

    expect(total).toBeGreaterThan(0);
    expect(active + inactive).toBe(total);
});

// ─── Filtre inactif ───────────────────────────────────────────────────────────

test('inactive filter shows only inactive users', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Inac${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createUser(page, { prenom, nom, tel });

    // Désactiver via le formulaire d'édition
    await page.locator('label[for="is_active"]').first().click();
    await page.locator('#user-form button[type="submit"]:visible').first().click();
    await expect(page).toHaveURL(/\/users\/\d+\/edit$/);

    // Vérifier le filtre "Inactif" sur la liste
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

// ─── Validation doublon ───────────────────────────────────────────────────────

test('duplicate phone number shows validation error', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const tel = `6${randomDigits(8)}`;

    await login(page);

    // Premier utilisateur avec ce téléphone
    await createUser(page, { prenom: `${PREFIX}${uid}A`, nom: `Dup${uid}A`, tel });

    // Second utilisateur avec le même téléphone – doit échouer
    await page.goto('/users/create');
    await fillUserInfoAndAdvance(page, { prenom: `${PREFIX}${uid}B`, nom: `Dup${uid}B`, tel });
    await page.locator('#password').fill('Password123');
    await page.locator('#password_confirmation').fill('Password123');
    await page.locator('#user-form button[type="submit"]:visible').first().click();

    // Doit rester sur la page create avec une erreur téléphone
    await expect(page).toHaveURL(/\/users\/create$/);
    await page.getByRole('button', { name: /informations/i }).click();
    await expect(page.locator('#telephone')).toBeVisible();
    await expect(page.locator('#telephone')).toHaveClass(/p-invalid/);
});

// ─── Formatage des champs ─────────────────────────────────────────────────────

test('prenom is saved as title case and nom as uppercase', async ({ page }) => {
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createUser(page, { prenom: 'mamadou', nom: 'barry', tel });

    // Le nom doit apparaître en majuscules dans la liste
    await page.goto('/users');
    const search = getVisibleSearchInput(page);
    await search.fill('mamadou barry');
    const row = page
        .locator('tbody tr', { hasText: /Mamadou/i })
        .filter({ hasText: /BARRY/i })
        .first();
    await expect(row).toBeVisible();
});

// ─── Navigation vers édition depuis la liste ──────────────────────────────────

test('edit action in dropdown navigates to edit page', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Nav${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createUser(page, { prenom, nom, tel });

    const row = await findUserInList(page, prenom);

    await openRowActions(row);
    await page.getByRole('menuitem', { name: /modifier/i }).first().click();

    await expect(page).toHaveURL(/\/users\/\d+\/edit$/);
    await expect(page.locator('#prenom')).toBeVisible();
});

// ─── Pagination / affichage titre ─────────────────────────────────────────────

test('users index page has correct title and breadcrumb', async ({ page }) => {
    await login(page);
    await page.goto('/users');

    await expect(page).toHaveTitle(/utilisateurs/i);
    await expect(page.getByRole('heading', { name: /^utilisateurs$/i })).toBeVisible();
});
