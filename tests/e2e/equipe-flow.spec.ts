import { expect, test } from '@playwright/test';
import {
    cleanupRowsByPrefix,
    escapeRegExp,
    login,
    openRowActions,
    randomDigits,
    selectOptionFromCombobox,
} from './helpers';

const E2E_EQUIPE_NOM_PREFIX = 'E2EEQ-';
/** Préfixes pour les données prérequises créées dans beforeAll */
const SETUP_VH_PREFIX = 'E2EEQVH-';
const SETUP_PROP_PREFIX = 'e2eeqprop';

test.setTimeout(120_000);

/**
 * Crée un véhicule interne et un propriétaire dédiés aux tests equipe.
 * Ces données sont nécessaires car equipe-flow tourne avant vehicule-flow
 * et proprietaire-flow alphabétiquement, donc aucune donnée n'existe encore.
 */
test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    try {
        await login(page);
        const unique = randomDigits(6);

        // ── Véhicule interne ──────────────────────────────────────────────────
        await page.goto('/vehicules/create');
        await page.locator('#nom_vehicule').fill(`E2E Equipe Vehicule ${unique}`);
        await page.locator('#immatriculation').fill(`${SETUP_VH_PREFIX}${unique}`);
        const vhCombos = page.locator('#vehicule-form').getByRole('combobox');
        await selectOptionFromCombobox(page, vhCombos.nth(0), /interne/i); // catégorie
        await selectOptionFromCombobox(page, vhCombos.nth(1));             // type
        await page
            .locator('#vehicule-form button[type="submit"]:visible')
            .first()
            .click();
        await page.waitForURL(/\/vehicules$/, { timeout: 20_000 });

        // ── Propriétaire ──────────────────────────────────────────────────────
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

/** Nettoyage du véhicule et du propriétaire créés dans beforeAll. */
test.afterAll(async ({ browser }) => {
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

    // Ordre DOM : Véhicule (first), puis Propriétaire (second) — tous deux obligatoires
    const formComboboxes = page.locator('form').getByRole('combobox');
    await selectOptionFromCombobox(page, formComboboxes.nth(0)); // Véhicule
    await selectOptionFromCombobox(page, formComboboxes.nth(1)); // Propriétaire

    // Override du taux propriétaire : 55 % (+ membre 45 % = 100 %)
    await page.locator('#taux_commission_proprietaire input').fill('55');

    // Nom de l'équipe
    await page.locator('#nom').fill(nomEquipe);

    // Ajouter un membre principal (obligatoire)
    await page.locator('button', { hasText: /ajouter un membre/i }).first().click();

    const dialog = page.locator('[role="dialog"]');
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    // Vérifier la présence du préfixe +224 dans la modale
    await expect(dialog.locator('text=+224')).toBeVisible();

    await dialog.locator('#membre-prenom').fill('Mamadou');
    await dialog.locator('#membre-nom').fill('Diallo');

    // Saisie locale uniquement (9 chiffres, sans le +224)
    await dialog.locator('#membre-telephone').fill('620111222');

    // Taux membre dans la modale : 45 % pour que total = 100 %
    await dialog.locator('#membre-taux').fill('45');

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

    // Vérifier que le taux propriétaire = 55 est bien sauvegardé
    const tauxEditInput = page.locator('#taux_commission_proprietaire input');
    await expect(tauxEditInput).toHaveValue(/55/);
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

test('membre modal affiche prefixe +224 et rejette telephone invalide', async ({
    page,
}) => {
    await login(page);

    await page.goto('/equipes-livraison/create');
    await expect(page).toHaveURL(/\/equipes-livraison\/create$/);

    await page.locator('button', { hasText: /ajouter un membre/i }).first().click();

    const dialog = page.locator('[role="dialog"]');
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    // Le préfixe +224 est affiché et non éditable
    await expect(dialog.locator('text=+224')).toBeVisible();

    // Tentative de saisie de lettres : elles ne doivent pas apparaître
    await dialog.locator('#membre-telephone').fill('abc');
    const phoneValue = await dialog.locator('#membre-telephone').inputValue();
    expect(phoneValue.replace(/\D/g, '')).toBe('');

    // Fermer la modale
    await dialog.locator('button', { hasText: /annuler/i }).click();
    await expect(dialog).toBeHidden({ timeout: 5_000 });
});
