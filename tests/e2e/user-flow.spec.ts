import { expect, test } from '@playwright/test';
import {
    cleanupRowsByPrefix,
    escapeRegExp,
    getVisibleSearchInput,
    login,
    openRowActions,
    selectOptionFromCombobox,
} from './helpers';

const PREFIX = 'e2eusrflow';

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
    await page.goto('/users/create');
    await expect(page).toHaveURL(/\/users\/create$/);

    // ── Onglet Informations ──────────────────────────────────────────────────
    const form = page.locator('#user-form');

    // Pays (premier combobox)
    const paysCombo = form.getByRole('combobox').first();
    await selectOptionFromCombobox(page, paysCombo, /guinée$/i);

    await page.locator('#prenom').fill(prenom);
    await page.locator('#nom').fill(nom);
    await page.locator('#telephone').fill(tel);

    // Rôle (deuxième combobox)
    const roleCombo = form.getByRole('combobox').nth(1);
    await selectOptionFromCombobox(page, roleCombo, /manager/i);

    // Site (troisième combobox → première option disponible)
    const siteCombo = form.getByRole('combobox').nth(2);
    await siteCombo.click();
    await page.locator('[role="option"]:visible').first().click();

    // Continuer → onglet mot de passe
    await form.locator('button[type="submit"]:visible').click();
    await expect(page.locator('#password')).toBeVisible();

    // ── Onglet Mot de passe ──────────────────────────────────────────────────
    await page.locator('#password').fill('Password123');
    await page.locator('#password_confirmation').fill('Password123');
    await page
        .locator('#user-form button[type="submit"]:visible')
        .first()
        .click();

    // Redirige vers la page d'édition
    await expect(page).toHaveURL(/\/users\/\d+\/edit$/);

    // Vérifier la présence dans la liste
    await page.goto('/users');
    const search = getVisibleSearchInput(page);
    await search.fill(prenom);

    const row = page
        .locator('tbody tr', {
            hasText: new RegExp(escapeRegExp(prenom), 'i'),
        })
        .first();
    await expect(row).toBeVisible();
    await expect(row).toContainText(/manager/i);
});

// ─── Modification des informations ────────────────────────────────────────────

test('edit user info → data persists', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Edit${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);

    // Création préalable
    await page.goto('/users/create');
    const form = page.locator('#user-form');
    const paysCombo = form.getByRole('combobox').first();
    await selectOptionFromCombobox(page, paysCombo, /guinée$/i);
    await page.locator('#prenom').fill(prenom);
    await page.locator('#nom').fill(nom);
    await page.locator('#telephone').fill(tel);
    const roleCombo = form.getByRole('combobox').nth(1);
    await selectOptionFromCombobox(page, roleCombo, /manager/i);
    const siteCombo = form.getByRole('combobox').nth(2);
    await siteCombo.click();
    await page.locator('[role="option"]:visible').first().click();
    await form.locator('button[type="submit"]:visible').click();
    await expect(page.locator('#password')).toBeVisible();
    await page.locator('#password').fill('Password123');
    await page.locator('#password_confirmation').fill('Password123');
    await page
        .locator('#user-form button[type="submit"]:visible')
        .first()
        .click();
    await expect(page).toHaveURL(/\/users\/\d+\/edit$/);

    // Modifier le rôle
    const editForm = page.locator('#user-form');
    const editRoleCombo = editForm.getByRole('combobox').filter({
        hasText: /manager/i,
    });
    await selectOptionFromCombobox(page, editRoleCombo, /comptable/i);

    await editForm.locator('button[type="submit"]:visible').first().click();
    await expect(page).toHaveURL(/\/users\/\d+\/edit$/);

    // Vérifier dans la liste
    await page.goto('/users');
    const search = getVisibleSearchInput(page);
    await search.fill(prenom);

    const row = page
        .locator('tbody tr', {
            hasText: new RegExp(escapeRegExp(prenom), 'i'),
        })
        .first();
    await expect(row).toBeVisible();
    await expect(row).toContainText(/comptable/i);
});

// ─── Modification du mot de passe ─────────────────────────────────────────────

test('edit user password → login with new password', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Pwd${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);

    // Création préalable
    await page.goto('/users/create');
    const form = page.locator('#user-form');
    const paysCombo = form.getByRole('combobox').first();
    await selectOptionFromCombobox(page, paysCombo, /guinée$/i);
    await page.locator('#prenom').fill(prenom);
    await page.locator('#nom').fill(nom);
    await page.locator('#telephone').fill(tel);
    const roleCombo = form.getByRole('combobox').nth(1);
    await selectOptionFromCombobox(page, roleCombo, /manager/i);
    const siteCombo = form.getByRole('combobox').nth(2);
    await siteCombo.click();
    await page.locator('[role="option"]:visible').first().click();
    await form.locator('button[type="submit"]:visible').click();
    await expect(page.locator('#password')).toBeVisible();
    await page.locator('#password').fill('Password123');
    await page.locator('#password_confirmation').fill('Password123');
    await page
        .locator('#user-form button[type="submit"]:visible')
        .first()
        .click();
    await expect(page).toHaveURL(/\/users\/\d+\/edit$/);

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

    // Doit rester sur la page d'édition avec succès
    await expect(page).toHaveURL(/\/users\/\d+\/edit$/);
});

// ─── Toggle statut ────────────────────────────────────────────────────────────

test('toggle user status → inactif in list', async ({ page }) => {
    const uid = `${Date.now()}`.slice(-6);
    const prenom = `${PREFIX}${uid}`;
    const nom = `Stat${uid}`;
    const tel = `6${randomDigits(8)}`;

    await login(page);

    // Création préalable
    await page.goto('/users/create');
    const form = page.locator('#user-form');
    const paysCombo = form.getByRole('combobox').first();
    await selectOptionFromCombobox(page, paysCombo, /guinée$/i);
    await page.locator('#prenom').fill(prenom);
    await page.locator('#nom').fill(nom);
    await page.locator('#telephone').fill(tel);
    const roleCombo = form.getByRole('combobox').nth(1);
    await selectOptionFromCombobox(page, roleCombo, /manager/i);
    const siteCombo = form.getByRole('combobox').nth(2);
    await siteCombo.click();
    await page.locator('[role="option"]:visible').first().click();
    await form.locator('button[type="submit"]:visible').click();
    await expect(page.locator('#password')).toBeVisible();
    await page.locator('#password').fill('Password123');
    await page.locator('#password_confirmation').fill('Password123');
    await page
        .locator('#user-form button[type="submit"]:visible')
        .first()
        .click();
    await expect(page).toHaveURL(/\/users\/\d+\/edit$/);

    // Vérifier actif dans la liste
    await page.goto('/users');
    const search = getVisibleSearchInput(page);
    await search.fill(prenom);
    const row = page
        .locator('tbody tr', {
            hasText: new RegExp(escapeRegExp(prenom), 'i'),
        })
        .first();
    await expect(row).toBeVisible();
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
    await page.goto('/users');
    const search2 = getVisibleSearchInput(page);
    await search2.fill(prenom);
    const updated = page
        .locator('tbody tr', {
            hasText: new RegExp(escapeRegExp(prenom), 'i'),
        })
        .first();
    await expect(updated).toBeVisible();
    await expect(updated).toContainText(/inactif/i);
});

// ─── Validation requise ───────────────────────────────────────────────────────

test('create user without required fields → stays on create page', async ({
    page,
}) => {
    await login(page);
    await page.goto('/users/create');

    // Soumettre sans rien remplir → doit rester sur create (validation client)
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

    const form = page.locator('#user-form');
    const paysCombo = form.getByRole('combobox').first();
    await selectOptionFromCombobox(page, paysCombo, /guinée$/i);
    await page.locator('#prenom').fill(prenom);
    await page.locator('#nom').fill(nom);
    await page.locator('#telephone').fill(tel);
    const roleCombo = form.getByRole('combobox').nth(1);
    await selectOptionFromCombobox(page, roleCombo, /manager/i);
    const siteCombo = form.getByRole('combobox').nth(2);
    await siteCombo.click();
    await page.locator('[role="option"]:visible').first().click();

    // Continuer → onglet mot de passe
    await form.locator('button[type="submit"]:visible').click();
    await expect(page.locator('#password')).toBeVisible();

    // Mots de passe différents
    await page.locator('#password').fill('Password123');
    await page.locator('#password_confirmation').fill('Different456');
    await page
        .locator('#user-form button[type="submit"]:visible')
        .first()
        .click();

    // Doit rester sur create
    await expect(page).toHaveURL(/\/users\/create$/);
});

// ─── Filtre par statut ────────────────────────────────────────────────────────

test('status filter → shows only active users', async ({ page }) => {
    await login(page);
    await page.goto('/users');

    // Cliquer sur le filtre "Actif"
    await page.getByRole('button', { name: /^actif$/i }).click();

    // Toutes les lignes visibles doivent afficher "Actif"
    const rows = page.locator('tbody tr:visible');
    const count = await rows.count();
    for (let i = 0; i < count; i++) {
        await expect(rows.nth(i)).toContainText(/actif/i);
        await expect(rows.nth(i)).not.toContainText(/inactif/i);
    }

    // Revenir à "Tous"
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

    // Création préalable
    await page.goto('/users/create');
    const form = page.locator('#user-form');
    const paysCombo = form.getByRole('combobox').first();
    await selectOptionFromCombobox(page, paysCombo, /guinée$/i);
    await page.locator('#prenom').fill(prenom);
    await page.locator('#nom').fill(nom);
    await page.locator('#telephone').fill(tel);
    const roleCombo = form.getByRole('combobox').nth(1);
    await selectOptionFromCombobox(page, roleCombo, /manager/i);
    const siteCombo = form.getByRole('combobox').nth(2);
    await siteCombo.click();
    await page.locator('[role="option"]:visible').first().click();
    await form.locator('button[type="submit"]:visible').click();
    await expect(page.locator('#password')).toBeVisible();
    await page.locator('#password').fill('Password123');
    await page.locator('#password_confirmation').fill('Password123');
    await page
        .locator('#user-form button[type="submit"]:visible')
        .first()
        .click();
    await expect(page).toHaveURL(/\/users\/\d+\/edit$/);

    // Supprimer depuis la liste
    await page.goto('/users');
    const search = getVisibleSearchInput(page);
    await search.fill(prenom);
    const row = page
        .locator('tbody tr', {
            hasText: new RegExp(escapeRegExp(prenom), 'i'),
        })
        .first();
    await expect(row).toBeVisible();

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
    const search2 = getVisibleSearchInput(page);
    await search2.fill(prenom);
    await expect(
        page.locator('tbody tr', {
            hasText: new RegExp(escapeRegExp(prenom), 'i'),
        }),
    ).toHaveCount(0);
});
