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
    await page.goto('/backoffice/vehicules');
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

test.beforeAll(async ({ browser }) => {
    test.setTimeout(120_000);
    // storageState explicite : browser.newContext() n'hérite PAS de
    // use.storageState (playwright.config.ts) — seule le fixture `page`
    // automatique le fait. Sans ça, login() ne peut pas court-circuiter sur
    // une session déjà valide et repasse par tout le flux UI à chaque hook,
    // ce qui a fait dépasser le timeout du hook en CI (contention sur le
    // rate limiter de connexion partagé entre tous les fichiers e2e).
    const context = await browser.newContext({
        storageState: '.auth/user.json',
    });
    const page = await context.newPage();
    try {
        await login(page);
        await ensureModuleEnabled(page, 'module.vehicules');
        const unique = randomDigits(6);

        await navigateToFirstSiteVehiclesTab(page);
        await page
            .getByTestId('add-site-vehicle-btn')
            .click({ timeout: 10_000 });
        await page.waitForURL(/\/vehicules\/create\?site_id=/, {
            timeout: 15_000,
        });
        await page
            .locator('#nom_vehicule')
            .fill(`${E2E_VH_PREFIX}${unique}`, { timeout: 10_000 });
        await page
            .locator('#immatriculation')
            .fill(`${SETUP_VH_PREFIX}${unique}`, { timeout: 10_000 });
        await selectOptionFromCombobox(page, page.locator('#type_vehicule'));
        // Plus de champ "catégorie" (interne/externe) ni de radio commission_eligible
        // (voir VehiculeForm.vue) — au moins un "usage" est requis pour activer le
        // bouton submit (canSubmit -> auMoinsUnUsage).
        await page
            .getByRole('checkbox', { name: /livraison vente/i })
            .check({ timeout: 10_000 });
        await page
            .locator('#vehicule-form button[type="submit"]:visible')
            .first()
            .click({ timeout: 10_000 });
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
    const context = await browser.newContext({
        storageState: '.auth/user.json',
    });
    const page = await context.newPage();
    try {
        await login(page);
        await cleanupRowsByPrefix(
            page,
            '/backoffice/vehicules',
            SETUP_VH_PREFIX,
        );
    } catch (e) {
        console.warn('E2E equipe afterAll cleanup warning:', e);
    } finally {
        await context.close();
    }
});

test.afterEach(async ({ browser }) => {
    try {
        const context = await browser.newContext({
            storageState: '.auth/user.json',
        });
        const page = await context.newPage();
        try {
            await login(page);
            await page.goto('/backoffice/equipes-livraison');
            const searchInput = page
                .locator(
                    'input[placeholder*="recherche" i]:not([data-testid="global-search"]):visible',
                )
                .first();
            await searchInput.fill(E2E_VH_PREFIX).catch(() => undefined);
            await searchInput.press('Enter').catch(() => undefined);
            await page.waitForLoadState('networkidle').catch(() => undefined);

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

    // La ligne 0 est auto-ajoutée — la remplir directement sans cliquer "Ajouter"
    await selectOptionFromCombobox(
        page,
        page.getByTestId('role-dropdown-0'),
        /chauffeur/i,
    );
    await page.getByTestId('nom-complet-0').fill('Mamadou Diallo');
    const phone0 = page.getByTestId('telephone-0');
    await phone0.click();
    await phone0.fill('620111222');

    // +224 affiché dans la ligne inline
    await expect(dialog.getByText('+224').first()).toBeVisible();
    // Nom complet visible dans le tableau
    await expect(page.getByTestId('nom-complet-0')).toHaveValue(
        'Mamadou Diallo',
    );

    // Passer à l'étape 2 (Partage) : le véhicule de test n'a aucun barème de
    // commission configuré (organisation "elm", partagée avec de nombreuses
    // autres specs — aucun CommissionRegle n'y est seedé par défaut, cf.
    // baremesCommissionCategories vide côté VehiculeController). L'étape
    // affiche donc l'état vide, sans aucune catégorie à répartir — le partage
    // reste "valide" par construction (rien à répartir), Suivant reste actif.
    // La démonstration de l'auto-complétion du reliquat (CommissionShareEditor
    // ::recomputeAutoFill) est couverte séparément par
    // commission-v2-full-chain.spec.ts, sur l'organisation dédiée "Eau La
    // Maman V2 Demo" qui configure elle-même son propre barème — jamais sur
    // "elm", pour ne pas polluer l'état partagé par toutes les autres specs.
    await dialog.getByRole('button', { name: /suivant/i }).click();
    await expect(dialog.getByText(/partage/i).first()).toBeVisible({
        timeout: 5_000,
    });
    await expect(
        dialog.getByText(/aucun barème de commission actif/i),
    ).toBeVisible({ timeout: 5_000 });

    // Passer à l'étape 3 — récapitulatif sans détail par catégorie (aucun
    // barème configuré, cf. ci-dessus) : seuls véhicule et nombre de
    // catégories (0) sont affichés.
    await dialog.getByRole('button', { name: /suivant/i }).click();
    await expect(dialog.getByText(/récapitulatif/i).first()).toBeVisible({
        timeout: 5_000,
    });
    await expect(
        dialog.getByText(new RegExp(escapeRegExp(E2E_VH_PREFIX), 'i')).first(),
    ).toBeVisible();

    // Enregistrer
    await dialog.getByRole('button', { name: /enregistrer l'équipe/i }).click();
    await expect(dialog).toBeHidden({ timeout: 20_000 });

    // Après enregistrement, la page véhicule montre les membres
    await expect(page).toHaveURL(/\/vehicules\/[a-z0-9]+$/, {
        timeout: 15_000,
    });
    await page
        .locator('aside button')
        .filter({ hasText: /equipe/i })
        .click();
    await expect(page.getByText(/Mamadou/i).first()).toBeVisible({
        timeout: 10_000,
    });
});

// L'auto-complétion du reliquat entre 2 bénéficiaires (CommissionShareEditor
// ::recomputeAutoFill) exigerait de configurer un barème de commission — mais
// l'organisation "elm" utilisée ici est partagée avec de nombreuses autres
// specs (achats, packing, produits…) et n'a délibérément aucun CommissionRegle
// seedé par défaut ; y configurer un barème depuis ce test pollueriait l'état
// partagé pour toutes les autres. Ce scénario est couvert sur l'organisation
// dédiée "Eau La Maman V2 Demo" (cf. commission-v2-full-chain.spec.ts), qui
// configure son propre barème isolé.

test('equipe index ne propose pas de bouton création directe', async ({
    page,
}) => {
    await page.goto('/backoffice/equipes-livraison');
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
    // La ligne est auto-ajoutée à l'ouverture — la supprimer pour revenir à 0 membres
    await dialog.locator('tbody tr').first().locator('button').click();
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

test('étape 1 inline : validation bloque si champs vides', async ({ page }) => {
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
    // Toujours à l'étape 1 : le bouton footer "Ajouter un membre" n'existe qu'à l'étape 1
    await expect(
        dialog.getByRole('button', { name: /ajouter un membre/i }),
    ).toBeVisible();
});

test('étape 1 inline : supprimer une ligne sans sous-modal', async ({
    page,
}) => {
    await openStepperModal(page);
    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /équipe/i });

    // La ligne 0 est auto-ajoutée — ajouter une seule ligne supplémentaire
    await dialog.getByRole('button', { name: /ajouter un membre/i }).click();

    // 2 champs nom complet visibles
    await expect(page.getByTestId('nom-complet-0')).toBeVisible();
    await expect(page.getByTestId('nom-complet-1')).toBeVisible();

    // Supprimer la première ligne (bouton poubelle dans la ligne 0)
    const rows = dialog.locator('tbody tr');
    await rows.first().locator('button').click();

    // Il ne reste plus qu'une ligne
    await expect(page.getByTestId('nom-complet-0')).toBeVisible();
    await expect(page.getByTestId('nom-complet-1')).not.toBeVisible();
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
    await page
        .locator('[role="dialog"]')
        .filter({ hasText: /équipe/i })
        .locator(
            '.p-dialog-close-button, .p-dialog-header-close, .p-dialog-header button',
        )
        .first()
        .click();

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
    await page
        .locator('[role="dialog"]')
        .filter({ hasText: /équipe/i })
        .locator(
            '.p-dialog-close-button, .p-dialog-header-close, .p-dialog-header button',
        )
        .first()
        .click();

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
    await page
        .locator('[role="dialog"]')
        .filter({ hasText: /équipe/i })
        .locator(
            '.p-dialog-close-button, .p-dialog-header-close, .p-dialog-header button',
        )
        .first()
        .click();

    // Aucune confirmation, modal fermé directement
    await expect(
        page
            .locator('[role="dialog"]')
            .filter({ hasText: /quitter sans enregistrer/i }),
    ).not.toBeVisible();
    await expect(dialog).toBeHidden({ timeout: 5_000 });
});
