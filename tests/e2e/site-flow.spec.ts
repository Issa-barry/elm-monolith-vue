import { expect, type Locator, type Page, test } from '@playwright/test';
import {
    closeFilterDrawerIfOpen,
    ensureModuleEnabled,
    escapeRegExp,
    getVisibleSearchInput,
    login,
    openRowActions,
    randomDigits,
    registerCleanup,
    selectOptionFromCombobox,
} from './helpers';

const PREFIX = 'e2esite';

test.setTimeout(180_000);

registerCleanup('/backoffice/sites', PREFIX);

test.beforeEach(async ({ page }) => {
    await login(page);
    await ensureModuleEnabled(page, 'module.sites');
});

function rowByName(page: Page, name: string): Locator {
    return page
        .locator('.p-datatable-table tbody tr', {
            hasText: new RegExp(escapeRegExp(name), 'i'),
        })
        .first();
}

async function createSite(page: Page, suffix: string): Promise<string> {
    const nom = `${PREFIX}-${suffix}`;

    await page.goto('/backoffice/sites/create');
    await expect(page).toHaveURL(/\/sites\/create$/, { timeout: 20_000 });

    await page.locator('#nom').fill(nom);
    await selectOptionFromCombobox(
        page,
        page.locator('#site-form').getByRole('combobox').first(),
    );
    await page.locator('#ville').fill('Conakry');
    await page.locator('#quartier').fill('Kaloum');
    await page.locator('#telephone').fill('+224620000000');

    await page
        .locator('#site-form button[type="submit"]:visible')
        .first()
        .click();

    await expect(page).toHaveURL(/\/sites$/, { timeout: 30_000 });

    return nom;
}

test('create site -> open details page', async ({ page }) => {
    const nom = await createSite(page, `${Date.now()}`.slice(-6));

    const search = await getVisibleSearchInput(page);
    await search.fill(nom);
    await search.press('Enter');
    await page.waitForLoadState('networkidle');

    const row = rowByName(page, nom);
    await expect(row).toBeVisible({ timeout: 15_000 });

    await openRowActions(row);
    await page
        .getByRole('menuitem', { name: /^voir$/i })
        .first()
        .click();

    await expect(page).toHaveURL(/\/sites\/[a-z0-9]+$/, { timeout: 20_000 });
    await expect(
        page.getByRole('heading', {
            name: new RegExp(escapeRegExp(nom), 'i'),
        }),
    ).toBeVisible({ timeout: 20_000 });
});

test('create site -> edit -> delete', async ({ page }) => {
    const nom = await createSite(
        page,
        `${Date.now()}${randomDigits(2)}`.slice(-8),
    );

    // Re-récupéré via getVisibleSearchInput() à chaque recherche (jamais le même Locator
    // réutilisé après une navigation) : sur une page DataFilters trigger-only comme
    // /backoffice/sites, ce Locator est scopé au drawer Filtres, qui se referme à
    // chaque rechargement — un `.fill()` dessus après coup n'a plus rien à cibler et
    // attend indéfiniment (pas de timeout explicite), jusqu'au timeout du test entier.
    let search = await getVisibleSearchInput(page);
    await search.fill(nom);
    await search.press('Enter');
    await page.waitForLoadState('networkidle');

    const row = rowByName(page, nom);
    await expect(row).toBeVisible({ timeout: 15_000 });

    await openRowActions(row);
    await page
        .getByRole('menuitem', { name: /modifier/i })
        .first()
        .click();

    await expect(page).toHaveURL(/\/sites\/[a-z0-9]+\/edit$/, {
        timeout: 20_000,
    });
    await expect(page.locator('#code')).toBeVisible();

    await page.locator('#quartier').fill('Ratoma');
    await page
        .locator('#site-form button[type="submit"]:visible')
        .first()
        .click();

    await expect(page).toHaveURL(/\/sites$/, { timeout: 20_000 });

    search = await getVisibleSearchInput(page);
    await search.fill(nom);
    await search.press('Enter');
    await page.waitForLoadState('networkidle');
    const updatedRow = rowByName(page, nom);
    await expect(updatedRow).toBeVisible({ timeout: 15_000 });

    await openRowActions(updatedRow);
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
    await search.fill(nom);
    await search.press('Enter');
    await page.waitForLoadState('networkidle');
    await expect(rowByName(page, nom)).toHaveCount(0);
});

test('en-tête standard : Exporter → Importer → Filtres → Nouveau, drawer fonctionnel', async ({
    page,
}) => {
    await page.goto('/backoffice/sites');
    await closeFilterDrawerIfOpen(page);

    const actions = page.getByTestId('list-page-actions');
    const buttons = actions.getByRole('button');
    const names = await buttons.allTextContents();
    const normalized = names.map((n) => n.trim().toLowerCase());

    // Ordre imposé par ListPageActions.vue, quel que soit l'ordre de
    // déclaration des slots côté page (cf. AGENTS.md §2).
    const exportIdx = normalized.findIndex((n) => n.includes('exporter'));
    const importIdx = normalized.findIndex((n) => n.includes('importer'));
    const filtresIdx = normalized.findIndex((n) => n.includes('filtres'));
    const nouveauIdx = normalized.findIndex((n) => n.includes('nouveau'));

    expect(exportIdx).toBeGreaterThanOrEqual(0);
    expect(importIdx).toBeGreaterThan(exportIdx);
    expect(filtresIdx).toBeGreaterThan(importIdx);
    expect(nouveauIdx).toBeGreaterThan(filtresIdx);

    // Le drawer Filtres s'ouvre et propose le filtre Type (pas de grande
    // barre de champs affichée avant ouverture).
    await buttons.nth(filtresIdx).click();
    const drawer = page.getByTestId('filters-drawer');
    await expect(drawer).toBeVisible({ timeout: 5_000 });
    // Scopé au drawer : la colonne "Type" du DataTable derrière matche aussi
    // "Type" en exact, sans quoi getByText résout deux éléments (violation du
    // mode strict de Playwright).
    await expect(drawer.getByText('Type', { exact: true })).toBeVisible();
});

test('mobile : le bouton Nouveau reste visible et Filtres reste accessible', async ({
    page,
}) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/backoffice/sites');
    await closeFilterDrawerIfOpen(page);

    await expect(
        page.getByRole('link', { name: /^nouveau$/i }).first(),
    ).toBeVisible({ timeout: 10_000 });

    const filtresBtn = page.getByRole('button', { name: /^filtres/i }).first();
    await expect(filtresBtn).toBeVisible({ timeout: 10_000 });
    await filtresBtn.click();
    await expect(page.getByTestId('filters-drawer')).toBeVisible({
        timeout: 5_000,
    });
});
