import { expect, type Locator, type Page, test } from '@playwright/test';
import {
    ensureModuleEnabled,
    escapeRegExp,
    login,
    randomDigits,
} from './helpers';

test.setTimeout(180_000);

test.beforeEach(async ({ page }) => {
    await login(page);
    await ensureModuleEnabled(page, 'module.depenses');
});

function depenseRowByComment(page: Page, comment: string): Locator {
    return page
        .locator('table tbody tr', {
            hasText: new RegExp(escapeRegExp(comment), 'i'),
        })
        .first();
}

async function selectConcerne(page: Page, value: string): Promise<void> {
    await page
        .locator(`input[type="radio"][value="${value}"]`)
        .check({ force: true });
    await expect(
        page.locator(`input[type="radio"][value="${value}"]`),
    ).toBeChecked({ timeout: 5_000 });
}

async function createDepenseInterne(
    page: Page,
    comment: string,
    montant: number,
): Promise<void> {
    await page.goto('/depenses/create');
    await expect(page).toHaveURL(/\/depenses\/create$/, { timeout: 20_000 });

    await selectConcerne(page, 'interne');

    await expect(page.locator('#dep-type')).not.toBeDisabled({
        timeout: 5_000,
    });
    await page.selectOption('#dep-type', { index: 1 });

    await expect(page.locator('#dep-montant')).toBeVisible({ timeout: 10_000 });
    await page.locator('#dep-montant').fill(String(montant));
    await page.locator('#dep-date').fill(new Date().toISOString().slice(0, 10));
    await page.locator('#dep-comment').fill(comment);

    await page
        .getByRole('button', { name: /enregistrer/i })
        .first()
        .click();
    await expect(page).toHaveURL(/\/depenses$/, { timeout: 30_000 });
}

async function createDepenseVehicule(
    page: Page,
    comment: string,
    montant: number,
): Promise<void> {
    await page.goto('/depenses/create');
    await expect(page).toHaveURL(/\/depenses\/create$/, { timeout: 20_000 });

    await selectConcerne(page, 'vehicule');

    await expect(page.locator('#dep-type')).not.toBeDisabled({
        timeout: 5_000,
    });
    await page.selectOption('#dep-type', { index: 1 });

    // Saisir véhicule via AutoComplete (PrimeVue 4 : clic sur le bouton dropdown du composant)
    const vehiculeAutocomplete = page
        .locator('#dep-vehicule')
        .locator('xpath=..');
    await vehiculeAutocomplete
        .locator('button')
        .first()
        .click({ timeout: 5_000 });
    const firstOption = page.locator('[role="option"]:visible').first();
    if (await firstOption.isVisible({ timeout: 5_000 })) {
        await firstOption.click({ timeout: 5_000 });
    }

    await expect(page.locator('#dep-montant')).toBeVisible({ timeout: 10_000 });
    await page.locator('#dep-montant').fill(String(montant));
    await page.locator('#dep-date').fill(new Date().toISOString().slice(0, 10));
    await page.locator('#dep-comment').fill(comment);

    await page
        .getByRole('button', { name: /enregistrer/i })
        .first()
        .click();
    await expect(page).toHaveURL(/\/depenses$/, { timeout: 30_000 });
}

// ── Tests ────────────────────────────────────────────────────────────────────

test('concerné first — type disabled tant que non choisi', async ({ page }) => {
    await page.goto('/depenses/create');
    await expect(page).toHaveURL(/\/depenses\/create$/, { timeout: 20_000 });

    await expect(page.locator('#dep-type')).toBeDisabled();

    await selectConcerne(page, 'interne');

    await expect(page.locator('#dep-type')).not.toBeDisabled({
        timeout: 5_000,
    });
});

test('changer concerné réinitialise type et bénéficiaire', async ({ page }) => {
    await page.goto('/depenses/create');

    await selectConcerne(page, 'vehicule');
    await expect(page.locator('#dep-type')).not.toBeDisabled({
        timeout: 5_000,
    });
    await page.selectOption('#dep-type', { index: 1 });

    // Changer de concerné
    await selectConcerne(page, 'interne');
    await expect(page.locator('#dep-type')).toHaveValue('');
});

test('create depense interne brouillon -> modifier -> supprimer', async ({
    page,
}) => {
    const suffix = `${Date.now()}${randomDigits(2)}`.slice(-8);
    const comment = `E2EDEP-${suffix}`;
    const montant = 7000 + Number(randomDigits(2));

    await createDepenseInterne(page, comment, montant);

    const row = depenseRowByComment(page, comment);
    await expect(row).toBeVisible({ timeout: 15_000 });
    await expect(row).toContainText(/brouillon/i);

    await row
        .getByRole('button', { name: /actions/i })
        .first()
        .click();
    await page
        .getByRole('menuitem', { name: /modifier/i })
        .first()
        .click();
    await expect(page).toHaveURL(/\/depenses\/[a-z0-9]+\/edit$/, {
        timeout: 20_000,
    });

    await page.locator('#dep-montant').fill('12345');
    await page
        .getByRole('button', { name: /enregistrer/i })
        .first()
        .click();
    await expect(page).toHaveURL(/\/depenses\/[a-z0-9]+$/, { timeout: 20_000 });

    await page.goto('/depenses');
    const updatedRow = depenseRowByComment(page, comment);
    await expect(updatedRow).toBeVisible({ timeout: 15_000 });

    await updatedRow
        .getByRole('button', { name: /actions/i })
        .first()
        .click();
    page.once('dialog', (dialog) => dialog.accept());
    await page
        .getByRole('menuitem', { name: /supprimer/i })
        .first()
        .click();
    await page.waitForLoadState('networkidle');

    await expect(depenseRowByComment(page, comment)).toHaveCount(0);
});

test('create depense véhicule', async ({ page }) => {
    const suffix = `${Date.now()}${randomDigits(2)}`.slice(-8);
    const comment = `E2EVEH-${suffix}`;

    await createDepenseVehicule(page, comment, 35000);

    const row = depenseRowByComment(page, comment);
    await expect(row).toBeVisible({ timeout: 15_000 });
    await expect(row).toContainText(/brouillon/i);
});

test('workflow brouillon -> soumis -> valide (dépense interne)', async ({
    page,
}) => {
    const suffix = `${Date.now()}${randomDigits(2)}`.slice(-8);
    const comment = `E2EWFL-${suffix}`;

    await createDepenseInterne(page, comment, 15000);

    const row = depenseRowByComment(page, comment);
    await expect(row).toBeVisible({ timeout: 15_000 });

    await row
        .getByRole('button', { name: /actions/i })
        .first()
        .click();
    await page
        .getByRole('menuitem', { name: /soumettre/i })
        .first()
        .click();
    await page.waitForLoadState('networkidle');

    await expect(depenseRowByComment(page, comment)).toContainText(/soumis/i);

    const rowSoumis = depenseRowByComment(page, comment);
    await rowSoumis
        .getByRole('button', { name: /actions/i })
        .first()
        .click();
    await page
        .getByRole('menuitem', { name: /valider/i })
        .first()
        .click();
    await page.waitForLoadState('networkidle');

    await expect(depenseRowByComment(page, comment)).toContainText(/valid/i);
});
