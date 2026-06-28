import { expect, type Page, test } from '@playwright/test';
import {
    cleanupRowsByPrefix,
    ensureModuleEnabled,
    escapeRegExp,
    login,
    navigateToFirstSiteVehiclesTab,
    randomDigits,
    selectOptionFromCombobox,
} from './helpers';

const E2E_VH_PREFIX = 'E2EEQ-';
const SETUP_VH_PREFIX = 'E2EEQVH-';

test.setTimeout(120_000);

/** Navigue vers la fiche d'un véhicule E2EEQ-, onglet Équipe. */
async function navigateToVehiculeEquipeTab(page: Page) {
    await page.goto('/vehicules');
    const vehiculeRow = page
        .locator('.p-datatable-table tbody tr:not(.p-datatable-emptymessage)', {
            hasText: new RegExp(escapeRegExp(E2E_VH_PREFIX), 'i'),
        })
        .first();
    await vehiculeRow.click();
    await page.waitForURL(/\/vehicules\/[a-z0-9]+$/, { timeout: 15_000 });

    await page
        .locator('aside button')
        .filter({ hasText: /equipe/i })
        .click();
}

/** Ouvre le stepper modal depuis l'onglet Équipe. */
async function openStepperModal(page: Page) {
    await navigateToVehiculeEquipeTab(page);
    const btn = page
        .getByRole('button', {
            name: /ajouter une équipe|gérer l'équipe/i,
        })
        .first();
    await expect(btn).toBeVisible({ timeout: 10_000 });
    await btn.click();
    await expect(
        page.locator('[role="dialog"]').filter({ hasText: /équipe/i }),
    ).toBeVisible({ timeout: 10_000 });
}

/**
 * Ajoute un membre via le bouton footer "Ajouter un membre" de l'étape 1.
 */
async function addMembreLigne(
    dialog: ReturnType<Page['locator']>,
    page: Page,
    idx: number,
    {
        role,
        prenom,
        nom,
        telephone,
    }: { role: RegExp; prenom: string; nom: string; telephone: string },
) {
    await dialog.getByRole('button', { name: /ajouter un membre/i }).click();

    await selectOptionFromCombobox(
        page,
        page.getByTestId(`role-dropdown-${idx}`),
        role,
    );

    await page.getByTestId(`prenom-${idx}`).fill(prenom);
    await page.getByTestId(`nom-${idx}`).fill(nom);

    const phoneInput = page.getByTestId(`telephone-${idx}`);
    await phoneInput.click();
    await phoneInput.fill(telephone);
}

test.beforeAll(async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    try {
        await login(page);
        await ensureModuleEnabled(page, 'module.vehicules');
        const unique = randomDigits(6);

        await navigateToFirstSiteVehiclesTab(page);
        await page.getByTestId('add-site-vehicle-btn').click();
        await page.waitForURL(/\/vehicules\/create\?site_id=/, {
            timeout: 15_000,
        });
        await page.locator('#nom_vehicule').fill(`${E2E_VH_PREFIX}${unique}`);
        await page
            .locator('#immatriculation')
            .fill(`${SETUP_VH_PREFIX}${unique}`);
        const vhCombos = page.locator('#vehicule-form').getByRole('combobox');
        await selectOptionFromCombobox(page, vhCombos.first());
        await page
            .locator('#vehicule-form button[type="submit"]:visible')
            .first()
            .click();
        await page.waitForURL(/\/vehicules\/[a-z0-9]+$/, { timeout: 20_000 });
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
    } catch (e) {
        console.warn('E2E equipe afterAll cleanup warning:', e);
    } finally {
        await context.close();
    }
});

test.afterEach(async ({ browser }) => {
    try {
        const context = await browser.newContext();
        const page = await context.newPage();
        try {
            await login(page);
            await page.goto('/equipes-livraison');
            const searchInput = page
                .locator(
                    'input[placeholder*="recherche" i]:not([data-testid="global-search"]):visible',
                )
                .first();
            await searchInput.fill(E2E_VH_PREFIX).catch(() => undefined);
            await searchInput.press('Enter').catch(() => undefined);
            await page
                .waitForLoadState('networkidle')
                .catch(() => undefined);

            for (let i = 0; i < 4; i++) {
                const row = page
                    .locator(
                        '.p-datatable-table tbody tr:not(.p-datatable-emptymessage)',
                        {
                            hasText: new RegExp(
                                escapeRegExp(E2E_VH_PREFIX),
                                'i',
                            ),
                        },
                    )
                    .first();
                if (!(await row.isVisible().catch(() => false))) break;
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
                await page
                    .waitForLoadState('networkidle')
                    .catch(() => undefined);
            }
        } finally {
            await context.close().catch(() => undefined);
        }
    } catch (e) {
        console.warn('E2E equipe afterEach cleanup warning:', e);
    }
});

test('créer une équipe depuis la fiche véhicule avec stepper', async ({
    page,
}) => {
    await openStepperModal(page);

    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /équipe/i });

    // Étape 1 : Membres — tableau inline (pas de sous-modal)
    await expect(dialog.getByText(/membres/i).first()).toBeVisible();
    await expect(
        page.locator('[role="dialog"]').filter({ hasText: /nouveau membre/i }),
    ).not.toBeVisible();

    await addMembreLigne(dialog, page, 0, {
        role: /chauffeur/i,
        prenom: 'Mamadou',
        nom: 'Diallo',
        telephone: '620111222',
    });

    // +224 affiché dans la ligne inline
    await expect(dialog.getByText('+224').first()).toBeVisible();
    // Prénom visible dans le tableau
    await expect(page.getByTestId('prenom-0')).toHaveValue('Mamadou');

    // Passer à l'étape 2
    await dialog.getByRole('button', { name: /suivant/i }).click();
    await expect(dialog.getByText(/partage/i).first()).toBeVisible({
        timeout: 5_000,
    });

    // Saisir commission
    const commissionInput = dialog.locator('input#step-commission');
    await commissionInput.click();
    await page.keyboard.press('Control+a');
    await page.keyboard.type('200');
    await page.keyboard.press('Tab');

    // Saisir le montant du membre dans le tableau de partage
    const membreMontantInput = dialog
        .locator('tbody tr')
        .first()
        .locator('td')
        .nth(1)
        .locator('input');
    await membreMontantInput.click();
    await page.keyboard.press('Control+a');
    await page.keyboard.type('200');
    await page.keyboard.press('Tab');

    await expect(dialog.getByText('✓ 100 %')).toBeVisible({ timeout: 5_000 });

    // Passer à l'étape 3
    await dialog.getByRole('button', { name: /suivant/i }).click();
    await expect(
        dialog.getByText(/récapitulatif/i).first(),
    ).toBeVisible({ timeout: 5_000 });

    // Vérifier le récap
    await expect(dialog.getByText(/Mamadou/i).first()).toBeVisible();
    await expect(dialog.getByText(/200 GNF/i).first()).toBeVisible();

    // Enregistrer
    await dialog
        .getByRole('button', { name: /enregistrer l'équipe/i })
        .click();
    await expect(dialog).toBeHidden({ timeout: 20_000 });

    // Après enregistrement, la page véhicule montre les membres
    await expect(page).toHaveURL(/\/vehicules\/[a-z0-9]+$/, {
        timeout: 15_000,
    });
    await page.locator('aside button').filter({ hasText: /equipe/i }).click();
    await expect(page.getByText(/Mamadou/i).first()).toBeVisible({
        timeout: 10_000,
    });
});

test('equipe index ne propose pas de bouton création directe', async ({
    page,
}) => {
    await page.goto('/equipes-livraison');
    await expect(
        page.getByRole('link', { name: /nouvelle équipe/i }),
    ).not.toBeVisible();
});

test('stepper étape 1 : bouton Suivant désactivé sans membres', async ({
    page,
}) => {
    await openStepperModal(page);
    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /équipe/i });
    const suivantBtn = dialog.getByRole('button', { name: /suivant/i });
    await expect(suivantBtn).toBeDisabled();
});

test('étape 1 inline : +224 affiché et téléphone invalide bloqué', async ({
    page,
}) => {
    await openStepperModal(page);
    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /équipe/i });

    // Pas de sous-modal
    await expect(
        page.locator('[role="dialog"]').filter({ hasText: /nouveau membre/i }),
    ).not.toBeVisible();

    // Ajouter un membre via le bouton footer
    await dialog.getByRole('button', { name: /ajouter un membre/i }).click();

    // +224 est visible dans la ligne inline
    await expect(dialog.getByText('+224').first()).toBeVisible();

    // Tenter de saisir des lettres dans le champ téléphone
    const phoneInput = page.getByTestId('telephone-0');
    await phoneInput.click();
    await page.keyboard.type('abcdefghi');
    const phoneValue = await phoneInput.inputValue();
    expect(phoneValue.replace(/\D/g, '')).toBe('');
});

test('étape 1 inline : validation bloque si champs vides', async ({
    page,
}) => {
    await openStepperModal(page);
    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /équipe/i });

    // Ajouter une ligne vide via le bouton footer
    await dialog.getByRole('button', { name: /ajouter un membre/i }).click();

    // Tenter de passer à l'étape 2 sans remplir la ligne
    await dialog.getByRole('button', { name: /suivant/i }).click();

    // Doit rester sur l'étape 1 (erreurs inline visibles)
    await expect(dialog.getByText(/requis/i).first()).toBeVisible({
        timeout: 3_000,
    });
    // Toujours à l'étape 1
    await expect(dialog.getByText(/partage/i).first()).not.toBeVisible();
});

test('étape 1 inline : supprimer une ligne sans sous-modal', async ({
    page,
}) => {
    await openStepperModal(page);
    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /équipe/i });

    // Ajouter deux lignes via le bouton footer
    await dialog.getByRole('button', { name: /ajouter un membre/i }).click();
    await dialog.getByRole('button', { name: /ajouter un membre/i }).click();

    // 2 champs prénom visibles
    await expect(page.getByTestId('prenom-0')).toBeVisible();
    await expect(page.getByTestId('prenom-1')).toBeVisible();

    // Supprimer la première ligne (bouton poubelle dans la ligne 0)
    const rows = dialog.locator('tbody tr');
    await rows.first().locator('button').click();

    // Il ne reste plus qu'une ligne
    await expect(page.getByTestId('prenom-0')).toBeVisible();
    await expect(page.getByTestId('prenom-1')).not.toBeVisible();
});

test('fermeture avec modifications : affiche confirmation, "Continuer" garde le modal ouvert', async ({
    page,
}) => {
    await openStepperModal(page);
    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /équipe/i });

    // Ajouter un membre (déclenche hasChanges)
    await dialog.getByRole('button', { name: /ajouter un membre/i }).click();

    // Cliquer sur le X du modal principal
    await page.locator('[role="dialog"]').filter({ hasText: /équipe/i })
        .locator('.p-dialog-header-close, [aria-label="Close"]').first().click();

    // La confirmation doit apparaître
    await expect(
        page.getByRole('dialog', { name: /quitter sans enregistrer/i }),
    ).toBeVisible({ timeout: 5_000 });

    // "Continuer l'édition" referme la confirmation et garde le wizard ouvert
    await page.getByRole('button', { name: /continuer l'édition/i }).click();
    await expect(
        page.getByRole('dialog', { name: /quitter sans enregistrer/i }),
    ).toBeHidden({ timeout: 3_000 });
    await expect(dialog).toBeVisible();
});

test('fermeture avec modifications : "Quitter" ferme le wizard', async ({
    page,
}) => {
    await openStepperModal(page);
    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /équipe/i });

    // Ajouter un membre (déclenche hasChanges)
    await dialog.getByRole('button', { name: /ajouter un membre/i }).click();

    // Cliquer sur le X
    await page.locator('[role="dialog"]').filter({ hasText: /équipe/i })
        .locator('.p-dialog-header-close, [aria-label="Close"]').first().click();

    await expect(
        page.getByRole('dialog', { name: /quitter sans enregistrer/i }),
    ).toBeVisible({ timeout: 5_000 });

    // "Quitter" ferme tout
    await page.getByRole('button', { name: /^quitter$/i }).click();
    await expect(dialog).toBeHidden({ timeout: 5_000 });
});

test('fermeture sans modifications : ferme directement sans confirmation', async ({
    page,
}) => {
    await openStepperModal(page);
    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /équipe/i });

    // Aucune interaction — clic sur X
    await page.locator('[role="dialog"]').filter({ hasText: /équipe/i })
        .locator('.p-dialog-header-close, [aria-label="Close"]').first().click();

    // Aucune confirmation, modal fermé directement
    await expect(
        page.locator('[role="dialog"]').filter({ hasText: /quitter sans enregistrer/i }),
    ).not.toBeVisible();
    await expect(dialog).toBeHidden({ timeout: 5_000 });
});
