import { expect, test } from '@playwright/test';
import { ensureModuleEnabled, login, randomDigits } from './helpers';

const E2E_PREFIX = '0E2E-Type-';

test.setTimeout(120_000);

test.beforeEach(async ({ page }) => {
    await login(page);
    await ensureModuleEnabled(page, 'module.depenses');
});

test('accès depuis le sous-menu Dépenses et redirection de l’ancienne URL', async ({
    page,
}) => {
    await page.goto('/backoffice/depenses');
    await expect(page).toHaveURL(/\/depenses$/);

    // Le sous-menu Dépenses expose désormais Liste des dépenses + Types de dépense. Il est
    // déjà déplié ici : NavMainItem.vue déplie automatiquement tout parent dont une route
    // fille est active (cf. isParentActive/isMenuOpen), et /backoffice/depenses en fait
    // partie — cliquer sur "Dépenses" le replierait plutôt que l'ouvrir. On ne clique donc
    // que s'il est encore replié, pour rester robuste aux deux états.
    const typesDeDepenseLink = page.getByRole('link', {
        name: 'Types de dépense',
    });
    if (!(await typesDeDepenseLink.isVisible().catch(() => false))) {
        await page
            .getByRole('button', { name: 'Dépenses', exact: true })
            .click();
    }
    await typesDeDepenseLink.click();
    await expect(page).toHaveURL(/\/backoffice\/depenses\/types$/, {
        timeout: 15_000,
    });
    await expect(
        page.getByRole('heading', { name: 'Types de dépense' }),
    ).toBeVisible();

    // Ancienne URL Paramètres — toujours accessible via une redirection propre.
    await page.goto('/settings/depense-types');
    await expect(page).toHaveURL(/\/backoffice\/depenses\/types$/, {
        timeout: 15_000,
    });
});

test('créer + vérifier + modifier + désactiver + supprimer un type de dépense', async ({
    page,
}) => {
    const unique = randomDigits(6);
    const nom = `${E2E_PREFIX}${unique}`;
    const nomModifie = `${E2E_PREFIX}Mod-${unique}`;

    await page.goto('/backoffice/depenses/types');
    await expect(page).toHaveURL(/\/depenses\/types$/);

    // Créer — le préfixe "0…" place toujours la ligne en tête de liste (tri
    // alphabétique par libellé côté backend), donc toujours visible en page 1
    // quel que soit le nombre de types déjà présents (seed par défaut inclus).
    await page.getByRole('button', { name: 'Nouveau type' }).click();
    // Le titre d'un Dialog PrimeVue (prop `header`) est un <span>, jamais un heading
    // ARIA — getByRole('heading', ...) ne matche donc jamais ici. On cible le dialog
    // lui-même par son texte, comme partout ailleurs dans la suite (ex: stock-ajustement.spec.ts).
    await expect(
        page.locator('[role="dialog"]').filter({ hasText: 'Nouveau type de dépense' }),
    ).toBeVisible();

    await page.locator('#dt-libelle').fill(nom);
    await page.locator('#dt-categorie').selectOption('interne');
    await page.getByRole('button', { name: 'Créer', exact: true }).click();

    const row = page.locator('table tbody tr', { hasText: nom }).first();
    await expect(row).toBeVisible({ timeout: 15_000 });
    await expect(row.getByText('Actif', { exact: true })).toBeVisible();

    // Modifier
    await row.getByTitle('Modifier').click();
    await expect(
        page.locator('[role="dialog"]').filter({ hasText: 'Modifier le type' }),
    ).toBeVisible();
    await page.locator('#dt-libelle').fill(nomModifie);
    await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();

    const editedRow = page
        .locator('table tbody tr', { hasText: nomModifie })
        .first();
    await expect(editedRow).toBeVisible({ timeout: 15_000 });

    // Désactiver — le statut est un point coloré (StatusDot), jamais un badge
    // à fond coloré.
    await editedRow.getByTitle('Désactiver').click();
    await expect(editedRow.getByText('Inactif', { exact: true })).toBeVisible({
        timeout: 10_000,
    });

    // Supprimer (aucune dépense ne référence ce type de test)
    page.on('dialog', (dialog) => dialog.accept());
    await editedRow.getByTitle('Supprimer').click();
    await expect(page.getByText(nomModifie)).not.toBeVisible({
        timeout: 10_000,
    });
});

test('exporter la liste des types de dépense en Excel et en PDF', async ({
    page,
}) => {
    await page.goto('/backoffice/depenses/types');
    await expect(page).toHaveURL(/\/depenses\/types$/);

    await page.getByTestId('depense-types-export-trigger').click();
    const [excelDownload] = await Promise.all([
        page.waitForEvent('download'),
        page.getByTestId('depense-types-export-excel').click(),
    ]);
    expect(excelDownload.suggestedFilename()).toMatch(/types-depense.*\.xlsx$/);

    await page.getByTestId('depense-types-export-trigger').click();
    const [pdfDownload] = await Promise.all([
        page.waitForEvent('download'),
        page.getByTestId('depense-types-export-pdf').click(),
    ]);
    expect(pdfDownload.suggestedFilename()).toMatch(/types-depense.*\.pdf$/);
    const pdfPath = await pdfDownload.path();
    expect(pdfPath).not.toBeNull();
});

test('télécharger le modèle puis importer les types de dépense', async ({
    page,
}) => {
    await page.goto('/backoffice/depenses/types');
    await expect(page).toHaveURL(/\/depenses\/types$/);

    // Étape 1 : télécharger le modèle (déjà rempli d'exemples importables).
    await page.getByTestId('depense-types-import-trigger').click();
    // <DropdownMenuItem as-child><a>...</a></DropdownMenuItem> (Index.vue) : le rôle
    // "menuitem" du wrapper est fusionné sur le <a> enfant et prime sur son rôle
    // implicite "link" — getByRole('link', ...) ne matcherait jamais cet élément.
    const [modeleDownload] = await Promise.all([
        page.waitForEvent('download'),
        page.getByRole('menuitem', { name: 'Télécharger le modèle' }).click(),
    ]);
    const modelePath = await modeleDownload.path();
    expect(modelePath).not.toBeNull();

    // Étape 2 : ré-importer ce même fichier via la modale.
    await page.getByTestId('depense-types-import-trigger').click();
    await page.getByTestId('depense-types-import-open').click();
    await expect(
        page
            .locator('[role="dialog"]')
            .filter({ hasText: 'Importer des types de dépense' }),
    ).toBeVisible();

    await page.locator('input[type="file"]').setInputFiles(modelePath!);
    await page.getByRole('button', { name: 'Analyser le fichier' }).click();

    // Le modèle contient 3 lignes d'exemple déjà valides — 0 erreur attendue,
    // ce qui débloque le bouton de confirmation (cf. peutConfirmer).
    await expect(page.getByText('en erreur')).toBeVisible({ timeout: 15_000 });
    const confirmButton = page.getByRole('button', {
        name: /confirmer l'import/i,
    });
    await expect(confirmButton).toBeEnabled({ timeout: 10_000 });

    await confirmButton.click();
    await expect(page.getByText(/import terminé/i)).toBeVisible({
        timeout: 15_000,
    });
    await expect(page.getByText(/3 types créés/i)).toBeVisible();
});
