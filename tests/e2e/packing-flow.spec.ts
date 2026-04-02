/**
 * packing-flow.spec.ts
 * E2E tests for packing management, including shift (jour/nuit).
 *
 * Run: npx playwright test tests/e2e/packing-flow.spec.ts --workers=1
 */
import { expect, type Locator, type Page, test } from '@playwright/test';
import { login, selectOptionFromCombobox } from './helpers';

test.setTimeout(180_000);

async function goToCreate(page: Page): Promise<void> {
    await page.goto('/packings/create');
    await page.waitForSelector('#packing-form', { timeout: 20_000 });
}

async function selectFirstPrestataire(page: Page): Promise<void> {
    const combo = page.locator('#packing-form').getByRole('combobox').first();
    await selectOptionFromCombobox(page, combo);
}

async function selectShift(page: Page, shift: 'Jour' | 'Nuit'): Promise<void> {
    const wrapper = page
        .locator('#shift')
        .locator('xpath=ancestor::*[@data-pc-name][1]');
    await expect(wrapper).toBeVisible({ timeout: 10_000 });
    await wrapper.click({ timeout: 5_000 });

    const option = page
        .locator('[role="option"]')
        .filter({ hasText: new RegExp(`^${shift}$`, 'i') })
        .first();
    await expect(option).toBeVisible({ timeout: 10_000 });
    await option.click({ timeout: 3_000 });
}

async function fillPrimeNumberInput(
    input: Locator,
    value: string,
): Promise<void> {
    await expect(input).toBeVisible({ timeout: 10_000 });
    await input.click({ timeout: 5_000 });
    await input.fill(value);
    await input.press('Tab');
}

async function fillAndSubmitPacking(page: Page): Promise<void> {
    const numberInputs = page.locator('#packing-form').getByRole('spinbutton');

    const nbInput = numberInputs.first();
    const prixInput = numberInputs.nth(1);

    await fillPrimeNumberInput(nbInput, '5');
    await fillPrimeNumberInput(prixInput, '1000');

    await page
        .locator('#packing-form button[type="submit"]:visible')
        .first()
        .click();
}

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

test('create packing with shift Jour -> show displays Jour', async ({
    page,
}) => {
    await login(page);
    await createPacking(page, 'Jour');

    await expect(page.locator('body')).toContainText(/jour/i, {
        timeout: 10_000,
    });
});

test('create packing with shift Nuit -> show displays Nuit', async ({
    page,
}) => {
    await login(page);
    await createPacking(page, 'Nuit');

    await expect(page.locator('body')).toContainText(/nuit/i, {
        timeout: 10_000,
    });
});

test('edit packing -> change shift Jour to Nuit -> persisted', async ({
    page,
}) => {
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

test('packing list shows shift label', async ({ page }) => {
    await login(page);
    await createPacking(page, 'Nuit');

    await page.goto('/packings');
    await expect(page.locator('body')).toContainText(/nuit/i, {
        timeout: 10_000,
    });
});

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

test('new packing form has Jour selected by default', async ({ page }) => {
    await login(page);
    await goToCreate(page);

    const shiftWrapper = page
        .locator('#shift')
        .locator('xpath=ancestor::*[@data-pc-name][1]');
    await expect(shiftWrapper).toBeVisible({ timeout: 10_000 });
    await expect(shiftWrapper).toContainText(/jour/i);
});
