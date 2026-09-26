import { expect, type Locator, type Page, test } from '@playwright/test';
import {
    ensureModuleEnabled,
    escapeRegExp,
    getVisibleSearchInput,
    login,
    openRowActions,
    randomDigits,
    registerCleanup,
    selectOptionFromCombobox,
} from './helpers';

const PREFIX = 'e2epresta';

test.setTimeout(180_000);

registerCleanup('/backoffice/prestataires', PREFIX);

test.beforeEach(async ({ page }) => {
    await login(page);
    await ensureModuleEnabled(page, 'module.prestataires');
});

function rowByText(page: Page, text: string): Locator {
    return page
        .locator('tbody tr', { hasText: new RegExp(escapeRegExp(text), 'i') })
        .first();
}

async function createPrestataire(page: Page, suffix: string): Promise<string> {
    const prenom = `${PREFIX}${suffix}`;
    const nom = 'Flow';
    const phone = `6${randomDigits(8)}`;

    await page.goto('/backoffice/prestataires/create');
    await expect(page).toHaveURL(/\/prestataires\/create$/, {
        timeout: 20_000,
    });

    const form = page.locator('#prestataire-form');
    await selectOptionFromCombobox(
        page,
        form.getByRole('combobox').first(),
        /machiniste/i,
    );

    await page.locator('#prenom').fill(prenom);
    await page.locator('#nom').fill(nom);
    await page.locator('#ville').fill('Conakry');
    await page.locator('#phone').fill(phone);

    await form.locator('button[type="submit"]:visible').first().click();
    await expect(page).toHaveURL(/\/prestataires$/, { timeout: 30_000 });

    return prenom;
}

test('create prestataire -> edit status -> verify inactive in list', async ({
    page,
}) => {
    const prenom = await createPrestataire(page, `${Date.now()}`.slice(-6));

    // Re-récupéré via getVisibleSearchInput() à chaque recherche (jamais le même
    // Locator réutilisé après une navigation) : sur une page DataFilters
    // trigger-only, ce Locator est scopé au drawer Filtres, qui se referme à
    // chaque rechargement — un `.fill()` dessus après coup n'a plus rien à cibler
    // et attend indéfiniment (pas de timeout explicite), jusqu'au timeout du test.
    let search = await getVisibleSearchInput(page);
    await search.fill(prenom);
    await search.press('Enter');
    await page.waitForLoadState('networkidle');

    const row = rowByText(page, prenom);
    await expect(row).toBeVisible({ timeout: 15_000 });
    await expect(row).toContainText(/actif/i);

    await openRowActions(row);
    await page
        .getByRole('menuitem', { name: /modifier/i })
        .first()
        .click();

    await expect(page).toHaveURL(/\/prestataires\/[a-z0-9]+\/edit$/, {
        timeout: 20_000,
    });

    await page.locator('label[for="is_active"]').first().click();
    await page
        .locator('#prestataire-form button[type="submit"]:visible')
        .first()
        .click();

    await expect(page).toHaveURL(/\/prestataires\/[a-z0-9]+\/edit$/, {
        timeout: 20_000,
    });

    await page.goto('/backoffice/prestataires');
    search = await getVisibleSearchInput(page);
    await search.fill(prenom);
    await search.press('Enter');
    await page.waitForLoadState('networkidle');

    const updatedRow = rowByText(page, prenom);
    await expect(updatedRow).toBeVisible({ timeout: 15_000 });
    await expect(updatedRow).toContainText(/inactif/i);
});

test('create prestataire -> delete from list', async ({ page }) => {
    const prenom = await createPrestataire(
        page,
        `${Date.now()}${randomDigits(2)}`.slice(-8),
    );

    let search = await getVisibleSearchInput(page);
    await search.fill(prenom);
    await search.press('Enter');
    await page.waitForLoadState('networkidle');

    const row = rowByText(page, prenom);
    await expect(row).toBeVisible({ timeout: 15_000 });

    await openRowActions(row);
    await page
        .getByRole('menuitem', { name: /supprimer/i })
        .first()
        .click();
    await page
        .getByRole('button', { name: /supprimer/i })
        .last()
        .click();

    await page.waitForLoadState('networkidle');
    search = await getVisibleSearchInput(page);
    await search.fill(prenom);
    await search.press('Enter');
    await page.waitForLoadState('networkidle');
    await expect(rowByText(page, prenom)).toHaveCount(0);
});
