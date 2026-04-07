import { expect, test } from '@playwright/test';
import { login } from './helpers';

test.setTimeout(240_000);

async function selectFirstVehicule(page: Parameters<typeof login>[0]) {
    const vehiculeAutocomplete = page.locator('#vente-form .p-autocomplete').first();

    await expect(vehiculeAutocomplete).toBeVisible({ timeout: 15_000 });
    await vehiculeAutocomplete.locator('button').first().click({ timeout: 5_000 });

    const firstOption = page.locator('[role="option"]:visible').first();
    await expect(firstOption).toBeVisible({ timeout: 10_000 });

    const optionText = (await firstOption.innerText()).trim();
    const vehiculeNom = optionText.split('\n')[0]?.trim() ?? optionText;

    await firstOption.click({ timeout: 5_000 });

    return vehiculeNom;
}

test('commande -> validation -> encaissement facture -> visible dans /factures', async ({
    page,
}) => {
    await login(page);

    await page.goto('/ventes/create');
    await expect(page).toHaveURL(/\/ventes\/create$/, { timeout: 20_000 });

    const vehiculeNom = await selectFirstVehicule(page);

    const submitCreate = page.locator('#vente-form button[type="submit"]:visible').first();
    await expect(submitCreate).toBeEnabled({ timeout: 10_000 });
    await submitCreate.click();

    await expect(page).toHaveURL(/\/ventes\/\d+$/, { timeout: 30_000 });

    const validerBtn = page.getByRole('button', { name: /valider la commande/i }).first();
    await expect(validerBtn).toBeVisible({ timeout: 20_000 });
    await validerBtn.click();

    await expect(page.locator('body')).toContainText(/facture/i, { timeout: 30_000 });
    await expect(page.locator('body')).toContainText(/ajouter un encaissement/i, {
        timeout: 20_000,
    });

    const addEncaissementBtn = page
        .getByRole('button', { name: /ajouter l'encaissement/i })
        .first();
    await expect(addEncaissementBtn).toBeEnabled({ timeout: 10_000 });

    const encaissementForm = addEncaissementBtn.locator('xpath=ancestor::form[1]');
    const montantInput = encaissementForm.locator('input').first();

    await expect(montantInput).toBeVisible({ timeout: 10_000 });
    await montantInput.fill('1000');
    await montantInput.press('Tab');

    await addEncaissementBtn.click();

    await expect(page.locator('body')).not.toContainText(/superieur a 0|supérieur à 0/i, {
        timeout: 15_000,
    });

    await page.goto('/factures?periode=all');
    await expect(page).toHaveURL(/\/factures/, { timeout: 20_000 });
    await expect(page.locator('body')).toContainText(/factures de vente/i, {
        timeout: 20_000,
    });

    const row = page.locator('tbody tr', { hasText: new RegExp(vehiculeNom, 'i') }).first();
    await expect(row).toBeVisible({ timeout: 20_000 });
    await expect(row).toContainText(/partiel|pay/i, { timeout: 20_000 });
});
