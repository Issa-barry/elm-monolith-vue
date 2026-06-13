import { expect, type Page, test } from '@playwright/test';
import {
    cleanupRowsByPrefix,
    ensureModuleEnabled,
    escapeRegExp,
    login,
    navigateToFirstSiteVehiclesTab,
    openRowActions,
    randomDigits,
    selectOptionFromCombobox,
} from './helpers';

const E2E_EQUIPE_NOM_PREFIX = 'E2EEQ-';
const SETUP_VH_PREFIX = 'E2EEQVH-';
const SETUP_PROP_PREFIX = 'e2eeqprop';

test.setTimeout(120_000);

function equipeRowByPrefix(page: Page, prefix: string) {
    return page
        .locator('.p-datatable-table tbody tr:not(.p-datatable-emptymessage)', {
            hasText: new RegExp(escapeRegExp(prefix), 'i'),
        })
        .first();
}

test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    try {
        await login(page);
        await ensureModuleEnabled(page, 'module.vehicules');
        const unique = randomDigits(6);

        // Create an interne vehicle via the site detail Véhicules tab
        await navigateToFirstSiteVehiclesTab(page);
        await page.getByTestId('add-site-vehicle-btn').click();
        await page.waitForURL(/\/vehicules\/create\?site_id=/, {
            timeout: 15_000,
        });
        await page.locator('#nom_vehicule').fill(`E2EEQ-${unique}`);
        await page
            .locator('#immatriculation')
            .fill(`${SETUP_VH_PREFIX}${unique}`);
        // Only the type_vehicule combobox is active (categorie is locked to interne)
        const vhCombos = page.locator('#vehicule-form').getByRole('combobox');
        await selectOptionFromCombobox(page, vhCombos.first());
        await page
            .locator('#vehicule-form button[type="submit"]:visible')
            .first()
            .click();
        await page.waitForURL(/\/vehicules\/[a-z0-9]+$/, {
            timeout: 20_000,
        });

        await page.goto('/proprietaires/create');
        await page.locator('#prenom').fill(`${SETUP_PROP_PREFIX}${unique}`);
        await page.locator('#nom').fill('E2ETest');
        await page.locator('#telephone').fill(`620${unique}`);
        await page
            .locator('#proprietaire-form button[type="submit"]:visible')
            .first()
            .click();
        await page.waitForURL(/\/proprietaires$/, { timeout: 20_000 });
    } finally {
        await context.close();
    }
});

test.beforeEach(async ({ page }) => {
    await login(page);
    await ensureModuleEnabled(page, 'module.vehicules');
});

test.afterAll(async ({ browser }) => {
    test.setTimeout(90_000);
    const context = await browser.newContext();
    const page = await context.newPage();
    try {
        await login(page);
        await cleanupRowsByPrefix(page, '/vehicules', SETUP_VH_PREFIX);
        await cleanupRowsByPrefix(page, '/proprietaires', SETUP_PROP_PREFIX);
    } catch (e) {
        console.warn('E2E equipe beforeAll cleanup warning:', e);
    } finally {
        await context.close();
    }
});

test.afterEach(async ({ browser }) => {
    try {
        const context = await browser.newContext();
        try {
            const page = await context.newPage();
            await login(page);
            await ensureModuleEnabled(page, 'module.vehicules');
            await page.goto('/equipes-livraison');

            const searchInput = page
                .locator('input[placeholder*="recherche" i]:visible')
                .first();
            await searchInput
                .fill(E2E_EQUIPE_NOM_PREFIX)
                .catch(() => undefined);

            for (let i = 0; i < 4; i++) {
                const row = equipeRowByPrefix(page, E2E_EQUIPE_NOM_PREFIX);
                if (!(await row.isVisible().catch(() => false))) break;

                try {
                    await row.locator('button').last().click({ timeout: 3000 });
                    const deleteItem = page
                        .getByRole('menuitem', { name: /supprimer/i })
                        .first();
                    if (!(await deleteItem.isVisible().catch(() => false)))
                        break;
                    await deleteItem.click({ timeout: 3000, force: true });

                    const confirmBtn = page
                        .getByRole('button', { name: /^supprimer$/i })
                        .last();
                    if (!(await confirmBtn.isVisible().catch(() => false)))
                        break;
                    await confirmBtn.click({ timeout: 3000 });
                } catch {
                    break;
                }

                await page
                    .waitForLoadState('networkidle')
                    .catch(() => undefined);
                await searchInput
                    .fill(E2E_EQUIPE_NOM_PREFIX)
                    .catch(() => undefined);
            }
        } finally {
            await context.close().catch(() => undefined);
        }
    } catch (e) {
        console.warn('E2E equipe cleanup warning:', e);
    }
});

test('create equipe with chauffeur and partage config + verify list', async ({
    page,
}) => {
    await page.goto('/equipes-livraison/create');
    await expect(page).toHaveURL(/\/equipes-livraison\/create$/);

    const formComboboxes = page.locator('form').getByRole('combobox');
    await selectOptionFromCombobox(
        page,
        formComboboxes.nth(0),
        new RegExp(escapeRegExp(E2E_EQUIPE_NOM_PREFIX), 'i'),
    );

    const proprietaireInput = page.locator('input#proprietaire_id');
    if (
        await proprietaireInput.isVisible({ timeout: 2_000 }).catch(() => false)
    ) {
        await selectOptionFromCombobox(page, formComboboxes.nth(1));
    }

    await page
        .locator('button', { hasText: /ajouter un membre/i })
        .first()
        .click();

    const membreDialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /nouveau membre/i });
    await expect(membreDialog).toBeVisible({ timeout: 10_000 });
    await expect(membreDialog.locator('text=+224')).toBeVisible();

    await selectOptionFromCombobox(
        page,
        membreDialog.getByRole('combobox').first(),
        /chauffeur/i,
    );
    await membreDialog.locator('#membre-prenom').fill('Mamadou');
    await membreDialog.locator('#membre-nom').fill('Diallo');
    await membreDialog.locator('#membre-telephone').fill('620111222');
    await membreDialog.locator('button', { hasText: /ajouter/i }).click();
    await expect(membreDialog).toBeHidden({ timeout: 5_000 });

    await page
        .locator('button', { hasText: /configurer le partage/i })
        .first()
        .click();

    const partageDialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /configurer le partage/i });
    await expect(partageDialog).toBeVisible({ timeout: 10_000 });

    const premierMontantInput = partageDialog
        .locator('tbody tr')
        .first()
        .locator('td')
        .nth(1)
        .locator('input');
    await premierMontantInput.click();
    await page.keyboard.press('Control+a');
    await page.keyboard.type('200');
    await page.keyboard.press('Tab');

    const validerBtn = partageDialog.locator('button', {
        hasText: /valider le partage/i,
    });
    await expect(validerBtn).toBeEnabled({ timeout: 5_000 });
    await validerBtn.click();
    await expect(partageDialog).toBeHidden({ timeout: 5_000 });

    await page.locator('form button[type="submit"]:visible').first().click();
    await expect(page).toHaveURL(/\/equipes-livraison\/[a-z0-9]+$/, { timeout: 20_000 });

    await page.goto('/equipes-livraison');
    await expect(page).toHaveURL(/\/equipes-livraison$/);

    const searchInput = page
        .locator('input[placeholder*="recherche" i]:visible')
        .first();
    await searchInput.fill(E2E_EQUIPE_NOM_PREFIX);

    const row = equipeRowByPrefix(page, E2E_EQUIPE_NOM_PREFIX);
    await expect(row).toBeVisible({ timeout: 10_000 });

    await openRowActions(row);
    await page
        .getByRole('menuitem', { name: /modifier/i })
        .first()
        .click();
    await expect(page).toHaveURL(/\/equipes-livraison\/[a-z0-9]+\/edit$/);

    await expect(page.getByText(/Mamadou/i).first()).toBeVisible({
        timeout: 10_000,
    });
});

test('store equipe echoue sans proprietaire', async ({ page }) => {
    await page.goto('/equipes-livraison/create');
    await expect(page).toHaveURL(/\/equipes-livraison\/create$/);

    const submitBtn = page
        .locator('form button[type="submit"]:visible')
        .first();
    await expect(submitBtn).toBeDisabled();
});

test('membre modal affiche prefixe +224 et rejette telephone invalide', async ({
    page,
}) => {
    await page.goto('/equipes-livraison/create');
    await expect(page).toHaveURL(/\/equipes-livraison\/create$/);

    await page
        .locator('button', { hasText: /ajouter un membre/i })
        .first()
        .click();

    const dialog = page.locator('[role="dialog"]');
    await expect(dialog).toBeVisible({ timeout: 10_000 });
    await expect(dialog.locator('text=+224')).toBeVisible();

    await dialog.locator('#membre-telephone').fill('abc');
    const phoneValue = await dialog.locator('#membre-telephone').inputValue();
    expect(phoneValue.replace(/\D/g, '')).toBe('');

    await dialog.locator('button', { hasText: /annuler/i }).click();
    await expect(dialog).toBeHidden({ timeout: 5_000 });
});
