import { expect, test } from '@playwright/test';
import { login, selectOptionFromCombobox } from './helpers';

test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/settings/commissions');
    await expect(
        page.getByRole('heading', { name: /^commissions$/i }),
    ).toBeVisible();
});

/**
 * Réécrit (04/09/2026) contre le VRAI markup actuel de CommissionRegles/Index.vue : la page a
 * été refondue en un dialog unique "Ajouter une catégorie"/"Modifier" avec une checkbox par
 * bénéficiaire, remplaçant l'ancien pattern d'une ligne inline avec un select "catégorie de la
 * ligne" directement dans le tableau (ce select, et le bouton "configurer le consultant" séparé,
 * n'existent plus). tests/e2e/commissions/helpers.ts avait déjà été réécrit pour ce même
 * refactor (cf. son docblock) mais ce fichier-ci était resté sur l'ancien pattern, donc cassé en
 * silence (les deux tests timeoutaient sur un sélecteur inexistant) sans lien avec le sujet du
 * chantier en cours au moment où la casse a été découverte (investigation d'un timeout E2E CI).
 */
test('impose la catégorie avant les bénéficiaires, puis les montants avant "Appliquer"', async ({
    page,
}) => {
    await page.getByTestId('commission-add-row').click();

    const dialog = page.getByRole('dialog', {
        name: /ajouter une catégorie/i,
    });
    await expect(dialog).toBeVisible();

    const category = dialog.locator('[aria-label="Catégorie"]');
    const proprietaire = dialog.getByRole('checkbox', {
        name: 'Propriétaire',
        exact: true,
    });
    const applyButton = page.getByTestId('commission-dialog-save');

    // Tant qu'aucune catégorie n'est choisie, la section bénéficiaires n'est même pas
    // rendue (v-if="dialogDraft.categorie_id") et "Appliquer" reste désactivé.
    await expect(proprietaire).toBeHidden();
    await expect(applyButton).toBeDisabled();

    await selectOptionFromCombobox(page, category);

    // Catégorie choisie : les bénéficiaires apparaissent, mais "Appliquer" reste
    // désactivé tant qu'aucun n'est coché (canApplyDialog exige au moins un bénéficiaire).
    await expect(proprietaire).toBeVisible();
    await expect(applyButton).toBeDisabled();

    await proprietaire.click();
    // canApplyDialog n'exige que catégorie + ≥1 bénéficiaire coché — le montant n'est
    // validé qu'au clic (validateDialog()), pas via l'état disabled du bouton.
    await expect(applyButton).toBeEnabled();

    // Bénéficiaire coché sans montant renseigné : le clic échoue et explique quoi
    // compléter, sans fermer le dialog.
    await applyButton.click();
    await expect(
        dialog.getByText('Montant entier requis (0 ou plus).'),
    ).toBeVisible();
    await expect(dialog).toBeVisible();

    await dialog
        .locator('[aria-label="Montant général — Propriétaire"]')
        .fill('500');
    await applyButton.click();
    await expect(dialog).toBeHidden();

    const row = page.locator('[data-testid^="commission-row-"]').last();
    await expect(row).toBeVisible();
});

/**
 * L'ancien test vérifiait qu'on pouvait changer la catégorie d'une ligne EXISTANTE, ce qui
 * réinitialisait tarifs et consultant — cette capacité n'existe plus : le select catégorie n'est
 * rendu qu'en mode "add" (dialogMode === 'add'), remplacé par un texte figé en mode "edit" (cf.
 * Index.vue ~L892-911). Changer de catégorie passe désormais par retirer la ligne puis en
 * recréer une — comportement stable depuis plusieurs chantiers commission (jamais exercé
 * autrement par tests/e2e/commissions/helpers.ts non plus). Réécrit pour couvrir le
 * comportement réel de "Modifier" : catégorie figée, bénéficiaires/montants toujours modifiables.
 */
test('modifier une ligne existante : catégorie figée, bénéficiaires modifiables', async ({
    page,
}) => {
    await page.getByTestId('commission-add-row').click();
    const addDialog = page.getByRole('dialog', {
        name: /ajouter une catégorie/i,
    });
    await selectOptionFromCombobox(
        page,
        addDialog.locator('[aria-label="Catégorie"]'),
    );
    await addDialog
        .getByRole('checkbox', { name: 'Propriétaire', exact: true })
        .click();
    await addDialog
        .locator('[aria-label="Montant général — Propriétaire"]')
        .fill('500');
    await page.getByTestId('commission-dialog-save').click();
    await expect(addDialog).toBeHidden();

    const row = page.locator('[data-testid^="commission-row-"]').last();
    const categorieLabel = await row
        .locator('p.truncate.font-medium')
        .first()
        .innerText();

    await row.getByRole('button', { name: /modifier/i }).click();
    const editDialog = page.getByRole('dialog', { name: /^modifier/i });
    await expect(editDialog).toBeVisible();

    // Pas de <Select> catégorie en édition — seulement son libellé, en lecture seule.
    await expect(editDialog.locator('[aria-label="Catégorie"]')).toHaveCount(
        0,
    );
    await expect(editDialog.getByText(categorieLabel, { exact: true })).toBeVisible();

    // Les bénéficiaires, eux, restent modifiables : ajouter Livreur en plus de Propriétaire.
    await editDialog
        .getByRole('checkbox', { name: 'Livreur', exact: true })
        .click();
    await editDialog
        .locator('[aria-label="Montant général — Livreur"]')
        .fill('300');

    await page.getByTestId('commission-dialog-save').click();
    await expect(editDialog).toBeHidden();
    await expect(row.getByText(/300/)).toBeVisible();
});
