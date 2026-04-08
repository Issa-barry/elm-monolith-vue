import { expect, test } from '@playwright/test';
import {
    escapeRegExp,
    login,
    openRowActions,
    randomDigits,
    selectOptionFromCombobox,
} from './helpers';

const E2E_EQUIPE_NOM_PREFIX = 'E2EEQ-';

test.setTimeout(120_000);

// Nettoyage après chaque test (placeholder equipes = "recherche")
test.afterEach(async ({ browser }) => {
    try {
        const context = await browser.newContext();
        try {
            const page = await context.newPage();
            await login(page);
            await page.goto('/equipes-livraison');

            const searchInput = page
                .locator('input[placeholder*="recherche" i]:visible')
                .first();
            await searchInput.fill(E2E_EQUIPE_NOM_PREFIX).catch(() => undefined);

            const guard = new RegExp(escapeRegExp(E2E_EQUIPE_NOM_PREFIX), 'i');

            for (let i = 0; i < 4; i++) {
                const row = page.locator('tbody tr', { hasText: guard }).first();
                if (!(await row.isVisible().catch(() => false))) break;

                try {
                    await row.locator('button').last().click({ timeout: 3000 });
                    const deleteItem = page
                        .getByRole('menuitem', { name: /supprimer/i })
                        .first();
                    if (!(await deleteItem.isVisible().catch(() => false))) break;
                    await deleteItem.click({ timeout: 3000, force: true });

                    const confirmBtn = page
                        .getByRole('button', { name: /^supprimer$/i })
                        .last();
                    if (!(await confirmBtn.isVisible().catch(() => false))) break;
                    await confirmBtn.click({ timeout: 3000 });
                } catch {
                    break;
                }

                await page.waitForLoadState('networkidle').catch(() => undefined);
                await searchInput.fill(E2E_EQUIPE_NOM_PREFIX).catch(() => undefined);
            }
        } finally {
            await context.close().catch(() => undefined);
        }
    } catch (e) {
        console.warn('E2E equipe cleanup warning:', e);
    }
});

test('create equipe with proprietaire + override taux + verify list', async ({
    page,
}) => {
    const unique = `${Date.now()}-${randomDigits(3)}`;
    const nomEquipe = `${E2E_EQUIPE_NOM_PREFIX}${unique.slice(-8)}`;

    await login(page);

    await page.goto('/equipes-livraison/create');
    await expect(page).toHaveURL(/\/equipes-livraison\/create$/);

    // Nom de l'équipe
    await page.locator('#nom').fill(nomEquipe);

    // Propriétaire (AutoComplete) — obligatoire
    const formComboboxes = page.locator('form').getByRole('combobox');
    await selectOptionFromCombobox(page, formComboboxes.first());

    // Override du taux propriétaire (InputNumber)
    await page.locator('#taux_commission_proprietaire input').fill('45');

    // Ajouter un membre principal (obligatoire)
    await page.locator('button', { hasText: /ajouter un membre/i }).first().click();

    const dialog = page.locator('[role="dialog"]');
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    await dialog.locator('#membre-prenom').fill('Mamadou');
    await dialog.locator('#membre-nom').fill('Diallo');
    await dialog.locator('#membre-telephone').fill('620111222');

    // Taux membre dans la modale
    await dialog.locator('#membre-taux').fill('30');

    // Confirmer le membre
    await dialog.locator('button', { hasText: /ajouter/i }).click();
    await expect(dialog).toBeHidden({ timeout: 5_000 });

    // Soumettre le formulaire
    await page
        .locator('form button[type="submit"]:visible')
        .first()
        .click();

    await expect(page).toHaveURL(/\/equipes-livraison$/, { timeout: 20_000 });

    // Vérifier la présence dans la liste
    const searchInput = page
        .locator('input[placeholder*="recherche" i]:visible')
        .first();
    await searchInput.fill(nomEquipe);

    const row = page
        .locator('tbody tr', {
            hasText: new RegExp(escapeRegExp(nomEquipe), 'i'),
        })
        .first();
    await expect(row).toBeVisible({ timeout: 10_000 });

    // Vérifier que l'équipe est modifiable (edit)
    await openRowActions(row);
    await page
        .getByRole('menuitem', { name: /modifier/i })
        .first()
        .click();

    await expect(page).toHaveURL(/\/equipes-livraison\/\d+\/edit$/);

    // Vérifier que le taux propriétaire = 45 est bien sauvegardé (suffixe " %" inclus)
    const tauxEditInput = page.locator('#taux_commission_proprietaire input');
    await expect(tauxEditInput).toHaveValue(/45/);
});

test('store equipe echoue sans proprietaire', async ({ page }) => {
    await login(page);

    await page.goto('/equipes-livraison/create');
    await expect(page).toHaveURL(/\/equipes-livraison\/create$/);

    // Remplir nom seulement, omettre propriétaire
    await page.locator('#nom').fill('Équipe Sans Proprio');

    // Le bouton Enregistrer doit rester désactivé tant que propriétaire est absent
    const submitBtn = page.locator('form button[type="submit"]:visible').first();
    await expect(submitBtn).toBeDisabled();
});
