import { expect, test } from '@playwright/test';
import { login, randomDigits } from './helpers';

const E2E_TYPE_PREFIX = 'E2EType-';

test.setTimeout(90_000);

test('login + create type vehicule + verify in list + edit + delete', async ({
    page,
}) => {
    const unique = randomDigits(6);
    const nom = `${E2E_TYPE_PREFIX}${unique}`;
    const nomModifie = `${E2E_TYPE_PREFIX}Mod-${unique}`;

    await login(page);

    // Step 1: Navigate to Types de véhicules
    await page.goto('/type-vehicules');
    await expect(page).toHaveURL(/\/type-vehicules$/);

    // Step 2: Create
    await page.getByRole('link', { name: /nouveau type/i }).click();
    await page.waitForURL(/\/type-vehicules\/create$/);

    await page.locator('#nom').fill(nom);
    // PrimeVue InputNumber renders a <span> wrapper — target the inner <input>
    await page.locator('#capacite_defaut input').fill('250');
    await page.locator('#unite_capacite').fill('packs');

    await page.getByRole('button', { name: /créer/i }).click();
    await page.waitForURL(/\/type-vehicules$/, { timeout: 15_000 });

    // Step 3: Verify in list
    await expect(page.getByText(nom)).toBeVisible({ timeout: 10_000 });

    // Step 4: Edit
    const row = page.locator('tbody tr', { hasText: nom });
    await row.getByRole('link').first().click();
    await page.waitForURL(/\/type-vehicules\/[a-z0-9]+\/edit$/, { timeout: 15_000 });

    await page.locator('#nom').fill(nomModifie);
    await page.getByRole('button', { name: /enregistrer/i }).click();
    await page.waitForURL(/\/type-vehicules$/, { timeout: 15_000 });

    await expect(page.getByText(nomModifie)).toBeVisible({ timeout: 10_000 });

    // Step 5: Delete (no vehicles attached)
    // Register dialog handler BEFORE clicking so confirm() is caught immediately
    page.on('dialog', (dialog) => dialog.accept());
    const editedRow = page.locator('tbody tr', { hasText: nomModifie });
    await editedRow.getByRole('button').last().click();
    // Inertia DELETE stays on the same URL — wait for the row to disappear
    await expect(page.getByText(nomModifie)).not.toBeVisible({ timeout: 10_000 });
});

test('default types appear in list', async ({ page }) => {
    await login(page);

    await page.goto('/type-vehicules');
    await expect(page).toHaveURL(/\/type-vehicules$/);

    // All default types (Camion, Minibus, Tricycle) should appear
    await expect(page.getByText('Camion')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByText('Minibus')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByText('Tricycle')).toBeVisible({ timeout: 10_000 });
});
