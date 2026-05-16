import { expect, type Page, test } from '@playwright/test';
import { E2E_PASSWORD, fillLoginIdentifier } from './helpers';

const PROPRIETAIRE_PHONE = '+33754158797';

test.setTimeout(180_000);

test.use({ storageState: { cookies: [], origins: [] } });

async function loginClientSpace(page: Page): Promise<void> {
    await page.goto('/login');
    await page.waitForSelector('input[name="password"]', { timeout: 20_000 });
    await fillLoginIdentifier(page, { phone: PROPRIETAIRE_PHONE });
    await page.locator('input[name="password"]').fill(E2E_PASSWORD);
    await page
        .getByRole('button', { name: /se connecter/i })
        .first()
        .click();
    await expect(page).not.toHaveURL(/\/login(?:\?.*)?$/, { timeout: 30_000 });
}

test('client space pages are accessible for proprietaire account', async ({
    page,
}) => {
    await loginClientSpace(page);

    await page.goto('/client/dashboard');
    await expect(page).toHaveURL(/\/client\/dashboard/, { timeout: 20_000 });
    await expect(page.locator('body')).toContainText(/bonjour/i, {
        timeout: 15_000,
    });

    const periodSelect = page.locator('select').first();
    await periodSelect.selectOption('7j');
    await expect(page).toHaveURL(/period=7j/, { timeout: 20_000 });

    await page.goto('/client/gains');
    await expect(page).toHaveURL(/\/client\/gains/, { timeout: 20_000 });
    await expect(page.locator('body')).toContainText(/gains et releve/i, {
        timeout: 15_000,
    });

    await page.goto('/client/vehicules');
    await expect(page).toHaveURL(/\/client\/vehicules/, { timeout: 20_000 });
    await expect(page.locator('body')).toContainText(/mes vehicules/i, {
        timeout: 15_000,
    });

    await page.goto('/client/profile');
    await expect(page).toHaveURL(/\/client\/profile/, { timeout: 20_000 });
    await expect(page.locator('body')).toContainText(/mon profil/i, {
        timeout: 15_000,
    });
});

test('client proposer-vehicule page renders form and history list', async ({
    page,
}) => {
    await loginClientSpace(page);

    await page.goto('/client/proposer-vehicule');
    await expect(page).toHaveURL(/\/client\/proposer-vehicule/, {
        timeout: 20_000,
    });
    await expect(page.locator('body')).toContainText(/nouvelle proposition/i, {
        timeout: 15_000,
    });
    await expect(
        page.locator('input[placeholder*="RC-001-GN"]').first(),
    ).toBeVisible({
        timeout: 10_000,
    });
});
