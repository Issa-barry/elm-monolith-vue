import { expect, test } from '@playwright/test';
import { login } from './helpers';

test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto('/settings/commissions');
    await expect(
        page.getByRole('heading', { name: /^commissions$/i }),
    ).toBeVisible();
});

test('impose la catégorie avant les montants et explique une ligne incomplète', async ({
    page,
}) => {
    await page.getByTestId('commission-add-row').click();
    const row = page.locator('[data-testid^="commission-row-"]').last();

    const category = row.getByLabel(/catégorie de la ligne/i);
    const proprietaire = row.getByLabel(/propriétaire/i);
    const livreur = row.getByLabel(/livreur/i);
    const site = row.getByLabel(/^site/i);
    const consultant = row.getByRole('button', {
        name: /configurer le consultant/i,
    });

    await expect(category).toBeEnabled();
    await expect(proprietaire).toBeDisabled();
    await expect(livreur).toBeDisabled();
    await expect(site).toBeDisabled();
    await expect(consultant).toBeDisabled();

    // Le bouton reste actionnable lorsqu'il existe des modifications : son clic
    // doit montrer précisément quoi compléter au lieu de rester silencieux.
    await page.getByTestId('commission-save').click();
    await expect(row.getByText('Choisissez une catégorie.')).toBeVisible();
    await expect(
        page.getByRole('dialog', { name: /vérifier avant d’enregistrer/i }),
    ).toBeHidden();

    await category.click();
    await page.getByRole('option').first().click();

    await expect(proprietaire).toBeEnabled();
    await expect(livreur).toBeEnabled();
    await expect(site).toBeEnabled();
    await expect(consultant).toBeEnabled();
});

test('réinitialise les tarifs et le consultant quand la catégorie change', async ({
    page,
}) => {
    await page.getByTestId('commission-add-row').click();
    const row = page.locator('[data-testid^="commission-row-"]').last();
    const category = row.getByLabel(/catégorie de la ligne/i);

    await category.click();
    await page.getByRole('option').first().click();

    const proprietaire = row.getByLabel(/propriétaire/i);
    const livreur = row.getByLabel(/livreur/i);
    const site = row.getByLabel(/^site/i);
    await proprietaire.fill('800');
    await livreur.fill('950');
    await site.fill('200');

    const consultantAction = row.getByRole('button', {
        name: /configurer le consultant/i,
    });
    await consultantAction.click();

    const dialog = page.getByRole('dialog', {
        name: /commission consultant/i,
    });
    await dialog.getByLabel(/consultant bénéficiaire/i).click();
    await page.getByRole('option').first().click();
    await dialog.getByLabel(/montant par unité vendue/i).fill('50');
    await dialog.getByTestId('commission-consultant-apply').click();
    await expect(dialog).toBeHidden();
    await expect(consultantAction).toContainText('50 GNF');

    await category.click();
    const categoryOptions = page.getByRole('option');
    expect(await categoryOptions.count()).toBeGreaterThan(1);
    await categoryOptions.nth(1).click();

    await expect(proprietaire).toHaveValue('');
    await expect(livreur).toHaveValue('');
    await expect(site).toHaveValue('');
    await expect(consultantAction).toContainText('Ajouter');
    await expect(consultantAction).not.toContainText('50 GNF');
});
