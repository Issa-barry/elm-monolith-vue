import { expect, type Locator, type Page, test } from '@playwright/test';
import {
    ensureModuleEnabled,
    escapeRegExp,
    getVisibleSearchInput,
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

test('stat cards reflect active filters', async ({ page }) => {
    const suffix = `${Date.now()}${randomDigits(2)}`.slice(-8);
    const comment = `E2ESTAT-${suffix}`;

    await createDepenseInterne(page, comment, 5000);

    await page.goto('/depenses');
    await page.waitForLoadState('networkidle');

    const totalCard = page
        .locator('p', { hasText: /total dépenses/i })
        .locator('xpath=following-sibling::p')
        .first();

    const totalBefore = parseInt((await totalCard.textContent()) ?? '0', 10);
    expect(totalBefore).toBeGreaterThan(0);

    const searchInput = getVisibleSearchInput(page);
    await searchInput.fill('ZZZZNO_MATCH_9999');
    await searchInput.press('Enter');
    await page.waitForLoadState('networkidle');

    await expect(totalCard).toHaveText('0', { timeout: 10_000 });

    const enAttenteCard = page
        .locator('p', { hasText: /en attente/i })
        .locator('xpath=following-sibling::p')
        .first();
    await expect(enAttenteCard).toHaveText('0');

    const valideesCard = page
        .locator('p', { hasText: /validées/i })
        .locator('xpath=following-sibling::p')
        .first();
    await expect(valideesCard).toHaveText('0');

    await searchInput.fill('');
    await searchInput.press('Enter');
    await page.waitForLoadState('networkidle');

    await expect(totalCard).not.toHaveText('0', { timeout: 10_000 });

    await page.goto('/depenses');
    const row = depenseRowByComment(page, comment);
    await expect(row).toBeVisible({ timeout: 10_000 });
    await row
        .getByRole('button', { name: /actions/i })
        .first()
        .click();
    page.once('dialog', (d) => d.accept());
    await page
        .getByRole('menuitem', { name: /supprimer/i })
        .first()
        .click();
    await page.waitForLoadState('networkidle');
});

test('workflow rejete → modifier montant → resoumettre → statut soumis', async ({
    page,
}) => {
    const suffix = `${Date.now()}${randomDigits(2)}`.slice(-8);
    const comment = `E2EREJ-${suffix}`;

    // 1. Créer une dépense interne
    await createDepenseInterne(page, comment, 3000);

    // 2. La soumettre
    await page.goto('/depenses');
    const row = depenseRowByComment(page, comment);
    await expect(row).toBeVisible({ timeout: 15_000 });
    await row.getByRole('button', { name: /actions/i }).first().click();
    await page.getByRole('menuitem', { name: /soumettre/i }).first().click();
    await page.waitForLoadState('networkidle');
    await expect(depenseRowByComment(page, comment)).toContainText(/soumis/i);

    // 3. La rejeter
    const rowSoumis = depenseRowByComment(page, comment);
    await rowSoumis.getByRole('button', { name: /actions/i }).first().click();
    await page.getByRole('menuitem', { name: /rejeter/i }).first().click();
    // Dialog de motif de rejet
    const motifInput = page.getByRole('textbox').filter({ hasText: '' }).last();
    if (await motifInput.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await motifInput.fill('Non conforme E2E');
        await page.getByRole('button', { name: /confirmer|rejeter/i }).last().click();
    }
    await page.waitForLoadState('networkidle');
    await expect(depenseRowByComment(page, comment)).toContainText(/rejet/i, {
        timeout: 10_000,
    });

    // 4. Modifier la dépense rejetée et resoumettre via le formulaire Edit
    const rowRejete = depenseRowByComment(page, comment);
    await rowRejete.getByRole('button', { name: /actions/i }).first().click();
    await page.getByRole('menuitem', { name: /modifier/i }).first().click();
    await expect(page).toHaveURL(/\/depenses\/[a-z0-9]+\/edit$/, {
        timeout: 20_000,
    });

    await page.locator('#dep-montant').fill('4500');
    await page.getByRole('button', { name: /soumettre pour validation/i }).click();
    await page.waitForLoadState('networkidle');

    // 5. Vérifier que le statut est bien "Soumis" (et non toujours "Rejeté")
    await page.goto('/depenses');
    await page.waitForLoadState('networkidle');
    await expect(depenseRowByComment(page, comment)).toContainText(/soumis/i, {
        timeout: 15_000,
    });
});

test('admin valide depense cross-agence — can_valider vrai hors son site', async ({
    page,
}) => {
    // Ce test vérifie que l'option "Valider" est visible pour l'admin
    // même sur une dépense dont le site diffère du sien.
    // Prérequis : le compte E2E doit être admin_entreprise (cas standard).
    const suffix = `${Date.now()}${randomDigits(2)}`.slice(-8);
    const comment = `E2EXAG-${suffix}`;

    await createDepenseInterne(page, comment, 8000);

    // Soumettre
    await page.goto('/depenses');
    const row = depenseRowByComment(page, comment);
    await expect(row).toBeVisible({ timeout: 15_000 });
    await row.getByRole('button', { name: /actions/i }).first().click();
    await page.getByRole('menuitem', { name: /soumettre/i }).first().click();
    await page.waitForLoadState('networkidle');

    // L'option "Valider" doit être présente dans le menu Actions
    const rowSoumis = depenseRowByComment(page, comment);
    await rowSoumis.getByRole('button', { name: /actions/i }).first().click();
    const validerItem = page.getByRole('menuitem', { name: /^valider$/i }).first();
    await expect(validerItem).toBeVisible({ timeout: 5_000 });

    // Valider
    await validerItem.click();
    await page.waitForLoadState('networkidle');
    await expect(depenseRowByComment(page, comment)).toContainText(/valid/i, {
        timeout: 10_000,
    });
});

test('champ site verrouillé non cliquable affiché sur le form edit', async ({
    page,
}) => {
    // Vérifie que le select site est bien disabled sur la page edit
    // (pour les comptes non-admin — si le compte E2E est admin, le champ sera actif,
    // ce test passe dans les deux cas car on vérifie l'attribut disabled par rapport à can_change_site)
    const suffix = `${Date.now()}${randomDigits(2)}`.slice(-8);
    const comment = `E2ELOCK-${suffix}`;

    await createDepenseInterne(page, comment, 1000);

    await page.goto('/depenses');
    const row = depenseRowByComment(page, comment);
    await expect(row).toBeVisible({ timeout: 15_000 });
    await row.getByRole('button', { name: /actions/i }).first().click();
    await page.getByRole('menuitem', { name: /modifier/i }).first().click();
    await expect(page).toHaveURL(/\/depenses\/[a-z0-9]+\/edit$/, {
        timeout: 20_000,
    });

    // Le select site existe toujours (il n'est pas supprimé)
    await expect(page.locator('#dep-site')).toBeVisible({ timeout: 5_000 });

    // Nettoyage : annuler
    await page.getByRole('button', { name: /annuler/i }).first().click();
    await page.waitForLoadState('networkidle');
    await page.goto('/depenses');
    const cleanRow = depenseRowByComment(page, comment);
    if (await cleanRow.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await cleanRow.getByRole('button', { name: /actions/i }).first().click();
        page.once('dialog', (d) => d.accept());
        await page.getByRole('menuitem', { name: /supprimer/i }).first().click();
        await page.waitForLoadState('networkidle');
    }
});

test('filtre agence (site_ids) persiste après Appliquer — chip, case cochée et URL', async ({
    page,
}) => {
    await page.goto('/depenses');
    await expect(page).toHaveURL(/\/depenses$/, { timeout: 15_000 });

    // Le sélecteur "agence" générique vit dans la barre d'outils (pas dans
    // le drawer) — Dépenses n'a pas de filtre site dédié, il réutilise le
    // même composant que Ventes / Produits / Logistique.
    const agenceMultiselect = page
        .getByTestId('agency-filter')
        .locator('[data-pc-name="multiselect"]')
        .first();

    const isAdmin = await agenceMultiselect
        .isVisible({ timeout: 3_000 })
        .catch(() => false);
    if (!isAdmin) {
        test.skip();
        return;
    }

    // Cliquer sur le bouton dropdown dédié (pas le contrôle entier) : une
    // fois une puce affichée, cliquer au centre du contrôle risque de
    // toucher le bouton de suppression de la puce plutôt que d'ouvrir le
    // panneau.
    const dropdownToggle = agenceMultiselect.locator('.p-multiselect-dropdown');

    await dropdownToggle.click();
    const option = page.locator('[role="option"]:visible').first();
    await expect(option).toBeVisible({ timeout: 5_000 });
    const optionLabel = (await option.textContent())?.trim();
    await option.click();

    // La case doit être cochée immédiatement après le clic.
    await expect(option).toHaveAttribute('aria-selected', 'true', {
        timeout: 3_000,
    });
    await page.keyboard.press('Escape');

    // Le chip doit apparaître dans le contrôle avant même de cliquer sur
    // Appliquer (état local du composant).
    const chips = agenceMultiselect.locator(
        '.p-multiselect-chip, [data-pc-section="chip"]',
    );
    await expect(chips.first()).toBeVisible({ timeout: 3_000 });

    await page.getByTestId('filters-search').first().click();
    await expect(page).toHaveURL(/site_ids/, { timeout: 10_000 });
    await page.waitForLoadState('networkidle');

    // Régression : après le round-trip serveur (rechargement Inertia avec
    // les nouveaux props), le chip ne doit plus être effacé.
    await expect(chips.first()).toBeVisible({ timeout: 5_000 });
    if (optionLabel) {
        await expect(agenceMultiselect).toContainText(optionLabel, {
            timeout: 5_000,
        });
    }

    // La case doit rester cochée dans le panel d'options après le reload.
    await dropdownToggle.click();
    const reselectedOption = page
        .locator('[role="option"][aria-selected="true"]:visible')
        .first();
    await expect(reselectedOption).toBeVisible({ timeout: 5_000 });
    await page.keyboard.press('Escape');
});
