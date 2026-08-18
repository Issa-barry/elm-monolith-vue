import { expect, test } from '@playwright/test';
import {
    escapeRegExp,
    getVisibleSearchInput,
    login,
    randomDigits,
    registerCleanup,
    selectOptionFromCombobox,
} from './helpers';

const E2E_PRODUCT_PREFIX = 'E2E Produit';

test.setTimeout(180_000);

registerCleanup('/backoffice/produits', E2E_PRODUCT_PREFIX);

test('login + create product + verify list', async ({ page }) => {
    const unique = `${Date.now()}-${randomDigits(3)}`;
    const productName = `${E2E_PRODUCT_PREFIX} ${unique}`;
    const codeBarres = `E2E-${unique}`;

    await login(page);

    await page.goto('/backoffice/produits/create');
    await expect(
        page.getByRole('heading', { name: /nouveau produit/i }),
    ).toBeVisible();

    await page.locator('#nom').fill(productName);
    await page.locator('#code_barres').fill(codeBarres);
    // Le type par défaut du formulaire ("Matériel") exige un prix d'achat
    // (cf. ProduitType::requiredPrices()) — sans ça la création échoue en validation
    // et reste sur /produits/create.
    await page.locator('#prix_achat').fill('1000');
    await page.getByRole('button', { name: /^enregistrer$/i }).click();

    // Création → redirige vers la fiche du produit (pas la liste).
    await expect(page).toHaveURL(/\/produits\/[^/]+$/);
    await expect(
        page.getByRole('heading', { name: productName }),
    ).toBeVisible();

    await page.goto('/backoffice/produits');
    const searchInput = getVisibleSearchInput(page);
    await searchInput.fill(productName);
    await searchInput.press('Enter');
    await page.waitForLoadState('networkidle');

    const productRow = page
        .locator('tbody tr', {
            hasText: new RegExp(escapeRegExp(productName), 'i'),
        })
        .first();
    await expect(productRow).toBeVisible();
});

test('create fabricable product with two-tier prix usine and verify persistence', async ({
    page,
}) => {
    const unique = `${Date.now()}-${randomDigits(3)}`;
    const productName = `${E2E_PRODUCT_PREFIX} Tricycle ${unique}`;

    await login(page);

    await page.goto('/backoffice/produits/create');
    await expect(
        page.getByRole('heading', { name: /nouveau produit/i }),
    ).toBeVisible();

    await page.locator('#nom').fill(productName);

    // Le type "Fabricable" est le seul à exiger prix_usine — condition d'affichage des deux
    // champs "Prix usine" dans ProduitForm.vue (cf. prixRequis('prix_usine')).
    const typeCombobox = page
        .locator('form, #produit-form')
        .getByRole('combobox')
        .first();
    await selectOptionFromCombobox(page, typeCombobox, /fabricable/i);

    await expect(page.locator('#prix_usine')).toBeVisible();
    await expect(page.locator('#prix_usine_tricycle')).toBeVisible();

    await page.locator('#prix_usine').fill('5100');
    await page.locator('#prix_usine_tricycle').fill('5050');
    await page.locator('#prix_vente').fill('6000');

    await page.getByRole('button', { name: /^enregistrer$/i }).click();

    await expect(page).toHaveURL(/\/produits\/[^/]+$/);
    await expect(
        page.getByRole('heading', { name: productName }),
    ).toBeVisible();

    // Persistance : les deux tarifs s'affichent distinctement sur la fiche produit.
    await expect(page.getByText('Prix usine — Autres véhicules')).toBeVisible();
    await expect(page.getByText('Prix usine — Tricycle')).toBeVisible();
    await expect(page.getByText('5 100')).toBeVisible();
    await expect(page.getByText('5 050')).toBeVisible();

    // Réouverture du formulaire d'édition : les deux valeurs restent bien distinctes.
    await page.getByRole('link', { name: /modifier/i }).first().click();
    await expect(page).toHaveURL(/\/produits\/[^/]+\/edit$/);
    // Le séparateur de groupement (locale fr-GN) n'est pas une espace ASCII classique — on
    // matche donc n'importe quel caractère entre les groupes de chiffres plutôt que la valeur
    // littérale.
    await expect(page.locator('#prix_usine')).toHaveValue(/^5.100$/);
    await expect(page.locator('#prix_usine_tricycle')).toHaveValue(/^5.050$/);
});
