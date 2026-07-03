import { expect, test } from '@playwright/test';
import { closeFilterDrawerIfOpen, login } from './helpers';

test.setTimeout(90_000);

test.beforeEach(async ({ page }) => {
    // Un test précédent peut avoir laissé le drawer de filtres ouvert ; son
    // overlay bloquerait alors les clics sur la barre d'outils.
    await closeFilterDrawerIfOpen(page);
});

test("filtre agence Kouria persiste après Rechercher, met à jour l'URL et filtre la liste (Ventes)", async ({
    page,
}) => {
    await login(page);
    await page.goto('/backoffice/ventes');
    await expect(page).toHaveURL(/\/ventes/, { timeout: 15_000 });

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

    // Référence avant filtrage — pour vérifier ensuite que la liste a bien
    // changé, sans dépendre d'une hypothèse sur les données seedées pour
    // Kouria (qui peut légitimement n'avoir aucune vente en CI).
    const resultCount = page.getByTestId('filters-result-count');
    const baselineText = (await resultCount.textContent()) ?? '';

    const dropdownToggle = agenceMultiselect.locator('.p-multiselect-dropdown');

    // 1. Sélectionner Kouria.
    await dropdownToggle.click();
    const kouriaOption = page
        .locator('[role="option"]:visible', { hasText: 'Kouria' })
        .first();
    await expect(kouriaOption).toBeVisible({ timeout: 5_000 });
    await kouriaOption.click();
    await expect(kouriaOption).toHaveAttribute('aria-selected', 'true', {
        timeout: 3_000,
    });
    await page.keyboard.press('Escape');

    const chips = agenceMultiselect.locator(
        '.p-multiselect-chip, [data-pc-section="chip"]',
    );
    await expect(chips.first()).toBeVisible({ timeout: 3_000 });
    await expect(agenceMultiselect).toContainText('Kouria');

    // 2. Cliquer sur Rechercher (bouton principal de la barre, pas le drawer).
    await page.getByTestId('filters-search').first().click();

    // 4. Vérifier que l'URL contient site_ids[].
    await expect(page).toHaveURL(/site_ids/, { timeout: 10_000 });
    await page.waitForLoadState('networkidle');

    // 3. Vérifier que Kouria reste sélectionnée après le round-trip Inertia
    // (chip toujours affiché + case toujours cochée dans le panel).
    await expect(chips.first()).toBeVisible({ timeout: 5_000 });
    await expect(agenceMultiselect).toContainText('Kouria');

    await dropdownToggle.click();
    const reselected = page
        .locator('[role="option"][aria-selected="true"]:visible', {
            hasText: 'Kouria',
        })
        .first();
    await expect(reselected).toBeVisible({ timeout: 5_000 });
    await page.keyboard.press('Escape');

    // 5. Vérifier que la liste est filtrée :
    //    - le compteur de résultats a changé par rapport à la référence
    //      avant filtrage (preuve que le filtre a bien été appliqué) ;
    //    - si des lignes restent affichées, chacune appartient à Kouria
    //      (colonne "Site", data-testid stable indépendant du texte affiché
    //      par les autres colonnes). Zéro résultat est un état valide : tous
    //      les sites n'ont pas forcément de vente seedée en CI.
    await expect(resultCount).not.toHaveText(baselineText, { timeout: 5_000 });

    const siteCells = page.getByTestId('row-site');
    const rowCount = await siteCells.count();
    for (let i = 0; i < rowCount; i++) {
        await expect(siteCells.nth(i)).toHaveText('Kouria');
    }
});
