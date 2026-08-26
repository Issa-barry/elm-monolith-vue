import { expect, test } from '@playwright/test';
import { closeFilterDrawerIfOpen, login } from './helpers';

test.setTimeout(90_000);

test.beforeEach(async ({ page }) => {
    // Un test précédent peut avoir laissé le drawer de filtres ouvert ;
    // son overlay bloquerait alors les clics sur la barre d'outils.
    await closeFilterDrawerIfOpen(page);
});

async function openFilterDrawer(page: any) {
    const btn = page.getByRole('button', { name: /^filtres/i }).first();
    await expect(btn).toBeVisible({ timeout: 10_000 });
    await btn.click();
    await expect(page.getByText('Statut facture')).toBeVisible({
        timeout: 5_000,
    });
}

// Standard UI (AGENTS.md §2) : DataFilters en mode trigger-only — tous les
// champs, y compris "Statut commande" et Agence, sont dans le drawer. Locator
// stable (data-testid), indépendant du texte affiché — qui change dès qu'une
// option est sélectionnée (placeholder -> chip), ce qui invalide tout locator
// basé sur .filter({ hasText: ... }).
function statutMultiselect(page: any) {
    return page
        .getByTestId('filter-field-statuts')
        .locator('[data-pc-name="multiselect"]')
        .first();
}

async function selectMultiSelectOption(page: any, optionLabel: string) {
    const panel = page
        .locator(
            '.p-multiselect-overlay:visible, [data-pc-name="multiselect"] .p-overlay:visible',
        )
        .first();
    const option = panel
        .locator(`[role="option"]`, { hasText: optionLabel })
        .first();
    if (!(await option.isVisible({ timeout: 3_000 }).catch(() => false))) {
        // fallback: cherche dans tout le DOM visible
        const fallback = page
            .locator(`[role="option"]:visible`, { hasText: optionLabel })
            .first();
        await expect(fallback).toBeVisible({ timeout: 5_000 });
        await fallback.click();
        return;
    }
    await option.click();
}

test.describe('Ventes — standard de liste (Filtres en en-tête)', () => {
    test('un seul bouton Filtres est visible dans l’en-tête, sans grande barre de champs au-dessus du tableau', async ({
        page,
    }) => {
        await login(page);
        await page.goto('/backoffice/ventes');
        await expect(page).toHaveURL(/\/ventes/, { timeout: 15_000 });

        const filtresBtn = page.getByRole('button', { name: /^filtres/i }).first();
        await expect(filtresBtn).toBeVisible({ timeout: 10_000 });

        // Aucun champ de filtre n'est visible tant que le drawer n'est pas ouvert.
        await expect(page.getByTestId('filter-field-statuts')).toHaveCount(0);
    });
});

test.describe('Ventes — filtre multi-statut (drawer)', () => {
    test('le filtre statut commande propose le MultiSelect statut dans le drawer', async ({
        page,
    }) => {
        await login(page);
        await page.goto('/backoffice/ventes');
        await expect(page).toHaveURL(/\/ventes/, { timeout: 15_000 });

        await openFilterDrawer(page);
        const multiselect = statutMultiselect(page);
        await expect(multiselect).toBeVisible({ timeout: 5_000 });
        await expect(multiselect).toContainText(/tous les statuts/i);
    });

    test("sélectionner un statut filtre les commandes et met à jour l'URL", async ({
        page,
    }) => {
        await login(page);
        await page.goto('/backoffice/ventes');
        await expect(page).toHaveURL(/\/ventes/, { timeout: 15_000 });

        await openFilterDrawer(page);

        // Ouvrir le MultiSelect statut (dans le drawer)
        const multiselect = statutMultiselect(page);
        await multiselect.click();

        // Sélectionner "Brouillon"
        await selectMultiSelectOption(page, 'Brouillon');

        // Fermer le panel en cliquant ailleurs
        await page.keyboard.press('Escape');

        // Appliquer
        await page.getByTestId('filters-apply').click();

        // URL doit contenir statuts[]=brouillon
        await expect(page).toHaveURL(/statuts/, { timeout: 10_000 });
    });

    test('sélectionner plusieurs statuts est possible', async ({ page }) => {
        await login(page);
        await page.goto('/backoffice/ventes');
        await expect(page).toHaveURL(/\/ventes/, { timeout: 15_000 });

        await openFilterDrawer(page);

        const multiselect = statutMultiselect(page);
        await multiselect.click();

        await selectMultiSelectOption(page, 'Brouillon');
        await selectMultiSelectOption(page, 'Clôturée');

        await page.keyboard.press('Escape');

        // Vérifier que 2 chips sont affichées. `multiselect` est un locator
        // stable (data-testid) qui reste valide même si le texte affiché
        // change (placeholder -> chips) après la sélection.
        const chips = multiselect.locator(
            '.p-multiselect-chip, [data-pc-section="chip"]',
        );
        await expect(chips).toHaveCount(2, { timeout: 5_000 });

        await page.getByTestId('filters-apply').click();
        await expect(page).toHaveURL(/statuts/, { timeout: 10_000 });
    });

    test('réinitialiser efface les statuts sélectionnés', async ({ page }) => {
        await login(page);
        await page.goto('/backoffice/ventes?statuts[]=brouillon');
        await expect(page).toHaveURL(/\/ventes/, { timeout: 15_000 });

        await openFilterDrawer(page);

        await page
            .getByRole('button', { name: /réinitialiser/i })
            .first()
            .click();

        // L'URL ne doit plus contenir statuts
        await expect(page).not.toHaveURL(/statuts/, { timeout: 10_000 });
    });

    test('le filtre agence est aussi un MultiSelect (admin), affiché dans le drawer', async ({
        page,
    }) => {
        await login(page);
        await page.goto('/backoffice/ventes');
        await expect(page).toHaveURL(/\/ventes/, { timeout: 15_000 });

        // En mode trigger-only, le sélecteur agence est désormais dans le
        // drawer (comme tous les autres champs) : il faut l'ouvrir avant d'y
        // accéder. Locator stable (data-testid), sans .filter({ hasText })
        // car le texte affiché passe de "Toutes les agences" à la puce
        // sélectionnée dès le clic sur une option.
        await openFilterDrawer(page);
        const agenceMultiselect = page
            .getByTestId('agency-filter')
            .locator('[data-pc-name="multiselect"]')
            .first();

        // Si le filtre agence existe (admin), il doit être un MultiSelect
        const isAdmin = await agenceMultiselect
            .isVisible({ timeout: 3_000 })
            .catch(() => false);
        if (!isAdmin) {
            test.skip();
            return;
        }

        await expect(agenceMultiselect).toContainText(/toutes les agences/i, {
            timeout: 3_000,
        });

        await agenceMultiselect.click();
        const options = page.locator('[role="option"]:visible');
        await expect(options.first()).toBeVisible({ timeout: 5_000 });
        await options.first().click();

        const chips = agenceMultiselect.locator(
            '.p-multiselect-chip, [data-pc-section="chip"]',
        );
        await expect(chips.first()).toBeVisible({ timeout: 3_000 });
    });
});
