import { expect, type Locator, type Page, test } from '@playwright/test';
import {
    ensureModuleEnabled,
    escapeRegExp,
    login,
    randomDigits,
    selectOptionFromCombobox,
} from './helpers';

test.setTimeout(180_000);

test.beforeEach(async ({ page }) => {
    await login(page);
    await ensureModuleEnabled(page, 'module.depenses');
});

function depenseRowByComment(page: Page, comment: string): Locator {
    return page
        .locator('div.rounded-lg.border > table > tbody > tr', {
            hasText: new RegExp(escapeRegExp(comment), 'i'),
        })
        .first();
}

async function createDraftDepense(
    page: Page,
    comment: string,
    montant: number,
): Promise<void> {
    await page.goto('/depenses/create');
    await expect(page).toHaveURL(/\/depenses\/create$/, { timeout: 20_000 });

    await page.selectOption('#dep-type', { index: 1 });
    await page.locator('#dep-montant').fill(String(montant));
    await page.locator('#dep-comment').fill(comment);

    const vehiculeInput = page.locator('#dep-vehicule').first();
    await selectOptionFromCombobox(page, vehiculeInput);

    await page
        .locator('input[type="radio"][value="brouillon"]')
        .check({ force: true });

    await page
        .getByRole('button', { name: /enregistrer/i })
        .first()
        .click();
    await expect(page).toHaveURL(/\/depenses$/, { timeout: 30_000 });
}

test('create depense brouillon -> edit -> delete', async ({ page }) => {
    const suffix = `${Date.now()}${randomDigits(2)}`.slice(-8);
    const comment = `E2EDEP-${suffix}`;
    const montant = 7000 + Number(randomDigits(2));

    await createDraftDepense(page, comment, montant);

    const row = depenseRowByComment(page, comment);
    await expect(row).toBeVisible({ timeout: 15_000 });
    await expect(row).toContainText(/brouillon/i);

    await row.locator('a[title="Modifier"]').first().click();
    await expect(page).toHaveURL(/\/depenses\/[a-z0-9]+\/edit$/, {
        timeout: 20_000,
    });

    const updatedMontant = 12345;
    await page.locator('#dep-montant').fill(String(updatedMontant));
    await page
        .getByRole('button', { name: /enregistrer/i })
        .first()
        .click();

    await expect(page).toHaveURL(/\/depenses$/, { timeout: 20_000 });

    const updatedRow = depenseRowByComment(page, comment);
    await expect(updatedRow).toBeVisible({ timeout: 15_000 });
    await expect(updatedRow).toContainText(/12[\s\u00A0\u202F]?345/);

    page.once('dialog', async (dialog) => {
        await dialog.accept();
    });
    await updatedRow.locator('button[title="Supprimer"]').first().click();
    await page.waitForLoadState('networkidle');

    await expect(depenseRowByComment(page, comment)).toHaveCount(0);
});
