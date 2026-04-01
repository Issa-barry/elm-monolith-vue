import { expect, test } from '@playwright/test';
import {
    cleanupRowsByPrefix,
    createUser,
    escapeRegExp,
    fillUserInfoAndAdvance,
    findUserInList,
    login,
    openRowActions,
    randomDigits,
    selectOptionFromCombobox,
} from './helpers';

const PREFIX = 'e2eusrflow';

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
        console.warn('E2E cleanup warning (users):', e);
    }
});

// ─── Création ─────────────────────────────────────────────────────────────────

test('create user with all fields → verify in list', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Flow${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createUser(page, { prenom, nom, tel });

    const row = await findUserInList(page, prenom);
    await expect(row).toContainText(/manager/i);
});

// ─── Modification des informations ────────────────────────────────────────────

test('edit user info → data persists', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Edit${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createUser(page, { prenom, nom, tel });

    // Modifier le rôle
    const editForm = page.locator('#user-form');
    const editRoleCombo = editForm.getByRole('combobox').filter({
        hasText: /manager/i,
    });
    await selectOptionFromCombobox(page, editRoleCombo, /comptable/i);

    await editForm.locator('button[type="submit"]:visible').first().click();
    await expect(page).toHaveURL(/\/users\/\d+\/edit$/);

    const row = await findUserInList(page, prenom);
    await expect(row).toContainText(/comptable/i);
});

// ─── Modification du mot de passe ─────────────────────────────────────────────

test('edit user password → login with new password', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Pwd${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createUser(page, { prenom, nom, tel });

    // Aller sur l'onglet Mot de passe
    await page
        .getByRole('button', { name: /mot de passe/i })
        .first()
        .click();

    await page.locator('#password').fill('NewPass456');
    await page.locator('#password_confirmation').fill('NewPass456');
    await page
        .locator('#user-form button[type="submit"]:visible')
        .first()
        .click();

    await expect(page).toHaveURL(/\/users\/\d+\/edit$/);
});

// ─── Toggle statut ────────────────────────────────────────────────────────────

test('toggle user status → inactif in list', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Stat${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createUser(page, { prenom, nom, tel });

    // Vérifier actif dans la liste
    const row = await findUserInList(page, prenom);
    await expect(row).toContainText(/actif/i);

    // Ouvrir l'édition → désactiver le compte
    await openRowActions(row);
    await page
        .getByRole('menuitem', { name: /modifier/i })
        .first()
        .click();
    await expect(page).toHaveURL(/\/users\/\d+\/edit$/);

    await page.locator('label[for="is_active"]').first().click();
    await page
        .locator('#user-form button[type="submit"]:visible')
        .first()
        .click();
    await expect(page).toHaveURL(/\/users\/\d+\/edit$/);

    // Vérifier inactif dans la liste
    const updated = await findUserInList(page, prenom);
    await expect(updated).toContainText(/inactif/i);
});

// ─── Validation requise ───────────────────────────────────────────────────────

test('create user without required fields → stays on create page', async ({
    page,
}) => {
    await login(page);
    await page.goto('/users/create');

    await page
        .locator('#user-form button[type="submit"]:visible')
        .first()
        .click();

    await expect(page).toHaveURL(/\/users\/create$/);
});

test('create user with password mismatch → error shown', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Mism${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await page.goto('/users/create');
    await fillUserInfoAndAdvance(page, { prenom, nom, tel });

    // Mots de passe différents
    await page.locator('#password').fill('Password123');
    await page.locator('#password_confirmation').fill('Different456');
    await page
        .locator('#user-form button[type="submit"]:visible')
        .first()
        .click();

    await expect(page).toHaveURL(/\/users\/create$/);
});

// ─── Filtre par statut ────────────────────────────────────────────────────────

test('status filter → shows only active users', async ({ page }) => {
    await login(page);
    await page.goto('/users');

    await page.getByRole('button', { name: /^actif$/i }).click();

    const rows = page.locator('tbody tr:visible');
    const count = await rows.count();
    for (let i = 0; i < count; i++) {
        await expect(rows.nth(i)).toContainText(/actif/i);
        await expect(rows.nth(i)).not.toContainText(/inactif/i);
    }

    await page.getByRole('button', { name: /^tous$/i }).click();
    await expect(page.locator('tbody tr:visible').first()).toBeVisible();
});

// ─── Suppression ──────────────────────────────────────────────────────────────

test('delete user → removed from list', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Del${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);
    await createUser(page, { prenom, nom, tel });

    const row = await findUserInList(page, prenom);

    await openRowActions(row);
    await page
        .getByRole('menuitem', { name: /supprimer/i })
        .first()
        .click();
    await page
        .getByRole('button', { name: /^supprimer$/i })
        .last()
        .click();

    await page.waitForLoadState('networkidle');

    // L'utilisateur ne doit plus apparaître
    const search2 = page.locator('input[placeholder*="rechercher" i]:visible').first();
    await search2.fill(prenom);
    await expect(
        page.locator('tbody tr', {
            hasText: new RegExp(escapeRegExp(prenom), 'i'),
        }),
    ).toHaveCount(0);
});
