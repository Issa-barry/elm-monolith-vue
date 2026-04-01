/**
 * packing-flow.spec.ts
 * Tests e2e pour la gestion des packings, incluant le champ shift (jour/nuit).
 *
 * Run: npx playwright test tests/e2e/packing-flow.spec.ts --workers=1
 */
import { expect, type Page, test } from '@playwright/test';
import { login, selectOptionFromCombobox } from './helpers';

test.setTimeout(180_000);

// ─── Helpers locaux ────────────────────────────────────────────────────────────

async function goToCreate(page: Page): Promise<void> {
    await page.goto('/packings/create');
    await page.waitForSelector('#packing-form', { timeout: 20_000 });
}

/**
 * Sélectionne le premier prestataire (1er combobox du formulaire).
 */
async function selectFirstPrestataire(page: Page): Promise<void> {
    const combo = page.locator('#packing-form').getByRole('combobox').first();
    await selectOptionFromCombobox(page, combo);
}

/**
 * Sélectionne un shift via le Dropdown (input-id="shift").
 * On clique sur le wrapper puis sur l'option.
 */
async function selectShift(page: Page, shift: 'Jour' | 'Nuit'): Promise<void> {
    // PrimeVue 4 Dropdown : le wrapper clickable contient l'input#shift
    const wrapper = page.locator('#shift').locator('xpath=ancestor::*[@data-pc-name][1]');
    await expect(wrapper).toBeVisible({ timeout: 10_000 });
    await wrapper.click({ timeout: 5_000 });

    // Attendre et cliquer sur l'option
    const option = page
        .locator('[role="option"]')
        .filter({ hasText: new RegExp(`^${shift}$`, 'i') })
        .first();
    await expect(option).toBeVisible({ timeout: 10_000 });
    await option.click({ timeout: 3_000 });
}

/**
 * Remplit le nombre de rouleaux (1er spinbutton = InputNumber PrimeVue 4) et soumet.
 * Prestataire et shift doivent déjà être sélectionnés.
 */
async function fillAndSubmitPacking(page: Page): Promise<void> {
    const nbInput = page
        .locator('#packing-form')
        .getByRole('spinbutton')
        .first();
    await expect(nbInput).toBeVisible({ timeout: 10_000 });
    await nbInput.fill('5');

    await page
        .locator('#packing-form button[type="submit"]:visible')
        .first()
        .click();
}

/**
 * Crée un packing complet et attend la redirection show.
 */
async function createPacking(
    page: Page,
    shift: 'Jour' | 'Nuit' = 'Jour',
): Promise<void> {
    await goToCreate(page);
    await selectFirstPrestataire(page);
    await selectShift(page, shift);
    await fillAndSubmitPacking(page);
    await expect(page).toHaveURL(/\/packings\/\d+$/, { timeout: 30_000 });
}

// ─── Création avec shift Jour ──────────────────────────────────────────────────

test('create packing with shift Jour → show displays Jour', async ({ page }) => {
    await login(page);
    await createPacking(page, 'Jour');

    await expect(page.locator('body')).toContainText(/jour/i, {
        timeout: 10_000,
    });
});

// ─── Création avec shift Nuit ──────────────────────────────────────────────────

test('create packing with shift Nuit → show displays Nuit', async ({ page }) => {
    await login(page);
    await createPacking(page, 'Nuit');

    await expect(page.locator('body')).toContainText(/nuit/i, {
        timeout: 10_000,
    });
});

// ─── Modification du shift via edit ───────────────────────────────────────────

test('edit packing → change shift Jour to Nuit → persisted', async ({ page }) => {
    await login(page);
    await createPacking(page, 'Jour');

    const editLink = page.getByRole('link', { name: /modifier/i }).first();
    await expect(editLink).toBeVisible({ timeout: 10_000 });
    await editLink.click();
    await expect(page).toHaveURL(/\/packings\/\d+\/edit$/, { timeout: 15_000 });
    await page.waitForSelector('#packing-form', { timeout: 15_000 });

    await selectShift(page, 'Nuit');
    await page
        .locator('#packing-form button[type="submit"]:visible')
        .first()
        .click();

    await expect(page).toHaveURL(/\/packings\/\d+$/, { timeout: 30_000 });
    await expect(page.locator('body')).toContainText(/nuit/i, {
        timeout: 10_000,
    });
});

// ─── Shift visible dans la liste ──────────────────────────────────────────────

test('packing list shows shift label', async ({ page }) => {
    await login(page);
    await createPacking(page, 'Nuit');

    await page.goto('/packings');
    await expect(page.locator('body')).toContainText(/nuit/i, {
        timeout: 10_000,
    });
});

// ─── Shift dans la page show ───────────────────────────────────────────────────

test('packing show page displays shift label and icon', async ({ page }) => {
    await login(page);
    await createPacking(page, 'Nuit');

    await expect(page.locator('body')).toContainText(/nuit/i, {
        timeout: 10_000,
    });
    await expect(page.locator('body')).toContainText('🌙', {
        timeout: 5_000,
    });
});

// ─── Shift par défaut = Jour ───────────────────────────────────────────────────

test('new packing form has Jour selected by default', async ({ page }) => {
    await login(page);
    await goToCreate(page);

    // Le wrapper du Dropdown shift doit afficher "Jour" par défaut
    const shiftWrapper = page
        .locator('#shift')
        .locator('xpath=ancestor::*[@data-pc-name][1]');
    await expect(shiftWrapper).toBeVisible({ timeout: 10_000 });
    await expect(shiftWrapper).toContainText(/jour/i);
});
