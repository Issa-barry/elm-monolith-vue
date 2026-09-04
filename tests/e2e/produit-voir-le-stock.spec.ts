import { expect, test, type Page } from '@playwright/test';
import { escapeRegExp, getVisibleSearchInput, login, randomDigits, registerCleanup } from './helpers';

const PREFIX = 'E2EVOIRSTOCK';

test.setTimeout(120_000);

registerCleanup('/backoffice/produits', PREFIX);

test.beforeEach(async ({ page }) => {
    await login(page);
});

/**
 * Crée un produit « fabricable » (gère le stock) puis lui affecte `qte` via la modale
 * « Ajuster le stock » de sa fiche détail — même mécanique que
 * vente-stock-insuffisant.spec.ts::creerProduitAvecStock(). Nécessaire pour que le produit
 * apparaisse réellement dans Produits/Stock/Index.vue, qui ne liste que les couples
 * variante × site ayant au moins un mouvement de stock.
 */
async function creerProduitAvecStock(
    page: Page,
    nom: string,
    qte: number,
): Promise<void> {
    await page.goto('/backoffice/produits/create');
    await page.locator('#nom').fill(nom);

    const typeCombobox = page
        .locator('form, #produit-form')
        .getByRole('combobox')
        .first();
    await typeCombobox.click();
    await page.getByRole('option', { name: /fabricable/i }).click();

    // Chaque champ InputNumber doit être "blurré" individuellement : PrimeVue InputNumber ne
    // committe la valeur dans le v-model qu'au blur, jamais sur le simple événement "input" de
    // .fill() (cf. produit-flow.spec.ts et stock-ajustement.spec.ts, même piège documenté).
    await page.locator('#prix_usine').fill('15000');
    await page.locator('#prix_usine').blur();
    await page.locator('#prix_usine_tricycle').fill('15000');
    await page.locator('#prix_usine_tricycle').blur();
    await page.locator('#prix_vente').fill('20000');
    await page.locator('#prix_vente').blur();

    // Tarification par nature de client — obligatoire pour un produit fabricable (cf.
    // ProduitService::raisonIncoherencePrix() côté serveur, seule source de vérité).
    await page.locator('#prix_externe').fill('20000');
    await page.locator('#prix_externe').blur();
    await page.locator('#prix_revendeur').fill('18000');
    await page.locator('#prix_revendeur').blur();
    await page.locator('#prix_distributeur').fill('17000');
    await page.locator('#prix_distributeur').blur();

    await page.getByRole('button', { name: /^enregistrer$/i }).click();
    await expect(page).toHaveURL(/\/produits\/[^/]+$/, { timeout: 20_000 });

    await page
        .locator('button', { hasText: /ajuster le stock/i })
        .first()
        .click();
    const dialog = page
        .locator('[role="dialog"]')
        .filter({ hasText: /ajuster le stock/i });
    await expect(dialog).toBeVisible({ timeout: 10_000 });

    const siteSelect = dialog.locator('[data-testid="stock-site-select"]');
    await siteSelect.click();
    await page.locator('[role="option"]').first().click();

    const augInput = dialog.locator(
        '[data-testid="stock-augmenter-input"] input',
    );
    await augInput.pressSequentially(String(qte));

    const motifSelect = dialog.locator('[data-testid="stock-motif-select"]');
    await motifSelect.click();
    await page.getByRole('option', { name: 'Après production' }).click();

    await dialog.locator('[data-testid="stock-submit-button"]').click();
    await expect(dialog).toBeHidden({ timeout: 10_000 });
}

/**
 * « Ajuster le stock » (modale directe) a été retiré du menu de la liste Produits — redondant
 * avec la page Stock, où site et variante sont clairement identifiés (décision produit du
 * 24/08/2026). Remplacé par « Voir le stock », qui ouvre Produits/Stock/Index.vue filtré sur
 * ce produit (réutilise le champ de recherche existant de cette page, cf. StockController::
 * stockQuery()). L'action « Ajuster le stock (par variante) », qui renvoie vers la fiche
 * détail du produit, reste inchangée pour les produits à déclinaisons.
 */
test('le menu Produits propose « Voir le stock » et plus « Ajuster le stock »', async ({
    page,
}) => {
    const unique = `${Date.now()}-${randomDigits(3)}`;
    const productName = `${PREFIX} ${unique}`;

    // Produit simple (une seule variante) avec du stock, pour faire apparaître l'action dans
    // le menu (v-if="data.has_stock").
    await page.goto('/backoffice/produits/create');
    await page.locator('#nom').fill(productName);
    await page.locator('#prix_achat').fill('1000');
    await page.locator('#prix_achat').blur();
    await page.getByRole('button', { name: /^enregistrer$/i }).click();
    await expect(page).toHaveURL(/\/produits\/[^/]+$/, { timeout: 20_000 });

    const adjustBtn = page.locator('button', { hasText: /ajuster le stock/i });
    if (await adjustBtn.isVisible({ timeout: 3_000 }).catch(() => false)) {
        await adjustBtn.click();
        const dialog = page
            .locator('[role="dialog"]')
            .filter({ hasText: /ajuster le stock/i });
        await expect(dialog).toBeVisible({ timeout: 10_000 });
        const siteSelect = dialog.locator('[data-testid="stock-site-select"]');
        await siteSelect.click();
        await page.locator('[role="option"]').first().click();
        const augInput = dialog.locator(
            '[data-testid="stock-augmenter-input"] input',
        );
        await augInput.pressSequentially('5');
        const motifSelect = dialog.locator('[data-testid="stock-motif-select"]');
        await motifSelect.click();
        await page.getByRole('option', { name: 'Après production' }).click();
        await dialog.locator('[data-testid="stock-submit-button"]').click();
        await expect(dialog).toBeHidden({ timeout: 10_000 });
    }

    await page.goto('/backoffice/produits');
    await expect(page).toHaveURL(/\/produits$/);
    const searchInput = await getVisibleSearchInput(page);
    await searchInput.fill(productName);
    await searchInput.press('Enter');
    await page.waitForLoadState('networkidle');

    const productRow = page
        .locator('tbody tr', {
            hasText: new RegExp(escapeRegExp(productName), 'i'),
        })
        .first();
    await expect(productRow).toBeVisible();
    await productRow.locator('button').last().click();

    await expect(
        page.getByRole('menuitem', { name: /^voir le stock$/i }),
    ).toBeVisible({ timeout: 5_000 });
    await expect(
        page.getByRole('menuitem', { name: /^ajuster le stock$/i }),
    ).toHaveCount(0);
});

test('« Voir le stock » ouvre la page Stock filtrée sur ce produit, sans les autres produits', async ({
    page,
}) => {
    const unique = `${Date.now()}-${randomDigits(3)}`;
    const productName = `${PREFIX} ${unique}`;
    // Produit témoin distinct, avec lui aussi du stock réel : sans stock, son absence des
    // résultats filtrés serait triviale et ne prouverait rien sur le filtre lui-même.
    const temoinName = `${PREFIX} Temoin ${unique}`;

    await creerProduitAvecStock(page, productName, 5);
    await creerProduitAvecStock(page, temoinName, 5);

    await page.goto('/backoffice/produits');
    await expect(page).toHaveURL(/\/produits$/);
    const searchInput = await getVisibleSearchInput(page);
    await searchInput.fill(productName);
    await searchInput.press('Enter');
    await page.waitForLoadState('networkidle');

    const productRow = page
        .locator('tbody tr', {
            hasText: new RegExp(escapeRegExp(productName), 'i'),
        })
        .first();
    await expect(productRow).toBeVisible();
    await productRow.locator('button').last().click();

    const voirStockItem = page.getByRole('menuitem', {
        name: /^voir le stock$/i,
    });
    await expect(voirStockItem).toBeVisible({ timeout: 5_000 });
    // force: true — l'item ferme le DropdownMenu (animation data-[state=closed]) au moment
    // même où Inertia <Link> déclenche la navigation ; Playwright interprète parfois ce
    // détachement concurrent comme une instabilité et relance click() depuis le début, qui
    // échoue alors car la page a déjà changé (item introuvable sur Stock/Index.vue) — repro
    // locale fiable (element was detached from the DOM, retrying → timeout 120s). L'élément
    // est déjà confirmé visible juste au-dessus : force: true saute uniquement la revérification
    // de stabilité, pas un vrai état caché/désactivé.
    await voirStockItem.click({ force: true });

    await expect(page).toHaveURL(/\/produits\/stock\?search=/, {
        timeout: 15_000,
    });

    // La page Stock est bien pré-filtrée sur CE produit : ses lignes apparaissent dans le
    // tableau (pas seulement quelque part sur la page), et le témoin — pourtant lui aussi en
    // stock — n'apparaît dans AUCUNE ligne des résultats filtrés. Pas de toHaveCount(1) sur le
    // produit filtré : Stock/Index.vue affiche une ligne par COUPLE variante × site (toutes les
    // agences de l'organisation, pas seulement celles ayant un stock ajusté), donc un même
    // produit y apparaît légitimement en plusieurs lignes.
    const stockRows = page.locator('[data-testid="stock-table"] tbody tr');
    await expect(
        stockRows.filter({ hasText: new RegExp(escapeRegExp(productName), 'i') }),
    ).not.toHaveCount(0, { timeout: 10_000 });
    await expect(
        stockRows.filter({ hasText: new RegExp(escapeRegExp(temoinName), 'i') }),
    ).toHaveCount(0);
});
