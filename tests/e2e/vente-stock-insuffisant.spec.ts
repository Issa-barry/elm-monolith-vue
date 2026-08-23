import { expect, test, type Page } from '@playwright/test';
import { login, randomDigits, registerCleanup } from './helpers';

const PREFIX = 'E2ESTOCKCMD';

test.setTimeout(180_000);

registerCleanup('/backoffice/produits', PREFIX);
registerCleanup('/backoffice/clients', PREFIX);

/**
 * Politique globale "Autoriser les ventes sans stock disponible" (Paramètres > Paramètres
 * produits) : coche explicitement Oui/Non plutôt que de supposer un état par défaut — même
 * principe que setImpayesControle() dans vente-controle-impayes-flow.spec.ts, indispensable
 * dans un environnement E2E partagé entre plusieurs fichiers de tests.
 */
async function setAutoriserVenteStockNegatif(
    page: Page,
    autoriser: boolean,
): Promise<void> {
    await page.goto('/settings/produits');
    await expect(page).toHaveURL(/\/settings\/produits$/, { timeout: 20_000 });

    const section = page
        .locator('div', {
            has: page.getByText(/gestion des ventes et du stock/i),
        })
        .first();
    await expect(section).toBeVisible({ timeout: 10_000 });

    const checkbox = section.getByLabel(autoriser ? 'Oui' : 'Non');
    await checkbox.check({ timeout: 5_000 });

    await section.getByRole('button', { name: /enregistrer/i }).click();
    await expect(page.locator('body')).toContainText(/mis[e]? à jour/i, {
        timeout: 10_000,
    });
}

/** Lit le nom du site rattaché à l'utilisateur connecté depuis /backoffice/ventes/create. */
async function readUserSiteName(page: Page): Promise<string> {
    await page.goto('/backoffice/ventes/create');
    await expect(page).toHaveURL(/\/ventes\/create$/, { timeout: 20_000 });

    const label = await page
        .locator('span.text-sm.font-medium')
        .first()
        .innerText();

    // Backend : "{type_label} de {site_nom}" (cf. CommandeVenteController::getUserSite()).
    const match = label.match(/\bde\s+(.+)$/i);
    return (match ? match[1] : label).trim();
}

/**
 * Crée un produit "fabricable" fraîchement créé (0 stock partout), puis lui affecte
 * exactement `qte` de stock sur le site dont le nom est `siteName`, via la fenêtre
 * "Ajuster le stock" de sa fiche — même mécanique que le test d'isolation multi-agences de
 * stock-ajustement.spec.ts (augmenter depuis une base connue à 0, jamais deviner un stock
 * préexistant).
 */
async function creerProduitAvecStock(
    page: Page,
    nom: string,
    siteName: string,
    qte: number,
): Promise<void> {
    await page.goto('/backoffice/produits/create');
    await expect(
        page.getByRole('heading', { name: /nouveau produit/i }),
    ).toBeVisible();

    await page.locator('#nom').fill(nom);

    const typeCombobox = page
        .locator('form, #produit-form')
        .getByRole('combobox')
        .first();
    await typeCombobox.click();
    await page.getByRole('option', { name: /fabricable/i }).click();

    await page.locator('#prix_usine').fill('15000');
    await page.locator('#prix_usine_tricycle').fill('15000');
    await page.locator('#prix_vente').fill('20000');
    await page.locator('#prix_vente').blur();

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
    await page
        .locator('[role="option"]', { hasText: siteName })
        .first()
        .click();

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

async function creerClientInApp(page: Page, nomComplet: string): Promise<void> {
    await page.goto('/backoffice/clients/create');
    await page.locator('#nom_complet').fill(nomComplet);

    const paysCombo = page
        .locator('#client-form')
        .getByRole('combobox')
        .first();
    await paysCombo.click();
    await page.getByRole('option', { name: /guin(?!.*bissau)/i }).click();

    await page.locator('#telephone').fill(randomDigits(9));
    await page
        .locator('#client-form button[type="submit"]:visible')
        .first()
        .click();
    await expect(page).toHaveURL(/\/clients\/[a-z0-9]+\/edit$/, {
        timeout: 15_000,
    });
}

async function selectClientOnVenteForm(
    page: Page,
    nomComplet: string,
): Promise<void> {
    const input = page.getByPlaceholder('Nom, prénom, téléphone…');
    await input.click();
    await input.fill(nomComplet);
    const option = page
        .locator('[role="option"]:visible', { hasText: nomComplet })
        .first();
    await expect(option).toBeVisible({ timeout: 10_000 });

    const solvabiliteResponse = page.waitForResponse(
        (res) =>
            res.url().includes('/ventes/check-solvabilite') &&
            res.url().includes('client_id='),
        { timeout: 15_000 },
    );
    await option.click();
    await solvabiliteResponse;
}

/** Sélectionne `produitNom` sur la première ligne, puis renseigne la quantité. */
async function remplirPremiereLigne(
    page: Page,
    produitNom: string,
    qte: number,
): Promise<void> {
    const produitDropdown = page
        .locator('#vente-form table tbody tr')
        .first()
        .locator('.p-dropdown, .p-select')
        .first();
    await produitDropdown.click();
    const filterInput = page.locator('.p-dropdown-filter, .p-select-filter').first();
    if (await filterInput.isVisible({ timeout: 2_000 }).catch(() => false)) {
        await filterInput.fill(produitNom);
    }
    await page
        .locator('[role="option"]:visible', { hasText: produitNom })
        .first()
        .click();

    const qteInput = page
        .locator('#vente-form table tbody tr')
        .first()
        .locator('input')
        .nth(1);
    await qteInput.fill('');
    await qteInput.pressSequentially(String(qte));
    await qteInput.blur();
}

async function soumettreEtConfirmer(page: Page): Promise<void> {
    await page
        .locator('#vente-form button[type="submit"]:visible')
        .first()
        .click();

    const confirmerEtCreerBtn = page.getByRole('button', {
        name: /confirmer et créer/i,
    });
    await expect(confirmerEtCreerBtn).toBeVisible({ timeout: 10_000 });
    await confirmerEtCreerBtn.click();
}

test.describe('Création de commande — contrôle du stock disponible', () => {
    test('540 demandés pour 460 disponibles (Non) : refusée, 460 (Non) : acceptée, 540 (Oui) : acceptée', async ({
        page,
    }) => {
        const unique = `${Date.now()}-${randomDigits(3)}`;
        const produitNom = `${PREFIX} ${unique}`;
        const clientNom = `${PREFIX} Client ${unique}`;

        await login(page);

        const siteName = await readUserSiteName(page);
        await creerProduitAvecStock(page, produitNom, siteName, 460);
        await creerClientInApp(page, clientNom);
        await setAutoriserVenteStockNegatif(page, false);

        // ── 540 demandés, 460 disponibles, politique Non → refusée ──────────────
        await page.goto('/backoffice/ventes/create');
        await expect(page).toHaveURL(/\/ventes\/create$/, { timeout: 20_000 });
        await selectClientOnVenteForm(page, clientNom);
        await remplirPremiereLigne(page, produitNom, 540);
        await soumettreEtConfirmer(page);

        // Refusée : reste sur /ventes/create (jamais redirigé vers /ventes/{id}), l'erreur
        // "Stock insuffisant" est visible dans le formulaire.
        await expect(page).toHaveURL(/\/ventes\/create$/, { timeout: 15_000 });
        await expect(page.locator('body')).toContainText(/stock insuffisant/i, {
            timeout: 10_000,
        });

        // ── 460 demandés, 460 disponibles, politique Non → acceptée ─────────────
        await remplirPremiereLigne(page, produitNom, 460);
        await soumettreEtConfirmer(page);
        await expect(page).toHaveURL(/\/ventes\/(?!create)[a-z0-9]+$/, {
            timeout: 20_000,
        });

        // ── Politique Oui : 540 demandés (stock désormais toujours 460, jamais décrémenté
        // à la création — seul le chargement décrémente réellement) → acceptée ───────────
        await setAutoriserVenteStockNegatif(page, true);

        await page.goto('/backoffice/ventes/create');
        await expect(page).toHaveURL(/\/ventes\/create$/, { timeout: 20_000 });
        await selectClientOnVenteForm(page, clientNom);
        await remplirPremiereLigne(page, produitNom, 540);
        await soumettreEtConfirmer(page);
        await expect(page).toHaveURL(/\/ventes\/(?!create)[a-z0-9]+$/, {
            timeout: 20_000,
        });
    });

    test('un produit à stock zéro reste masqué du sélecteur quand un autre produit est disponible (Non)', async ({
        page,
    }) => {
        const unique = `${Date.now()}-${randomDigits(3)}`;
        const produitAvecStock = `${PREFIX} AvecStock ${unique}`;
        const produitSansStock = `${PREFIX} SansStock ${unique}`;

        await login(page);
        await setAutoriserVenteStockNegatif(page, false);

        const siteName = await readUserSiteName(page);
        await creerProduitAvecStock(page, produitAvecStock, siteName, 10);

        // Produit fraîchement créé, 0 stock partout — jamais ajusté.
        await page.goto('/backoffice/produits/create');
        await page.locator('#nom').fill(produitSansStock);
        const typeCombobox = page
            .locator('form, #produit-form')
            .getByRole('combobox')
            .first();
        await typeCombobox.click();
        await page.getByRole('option', { name: /fabricable/i }).click();
        await page.locator('#prix_usine').fill('15000');
        await page.locator('#prix_usine_tricycle').fill('15000');
        await page.locator('#prix_vente').fill('20000');
        await page.locator('#prix_vente').blur();
        await page.getByRole('button', { name: /^enregistrer$/i }).click();
        await expect(page).toHaveURL(/\/produits\/[^/]+$/, { timeout: 20_000 });

        await page.goto('/backoffice/ventes/create');
        await expect(page).toHaveURL(/\/ventes\/create$/, { timeout: 20_000 });

        const produitDropdown = page
            .locator('#vente-form table tbody tr')
            .first()
            .locator('.p-dropdown, .p-select')
            .first();
        await produitDropdown.click();
        const filterInput = page
            .locator('.p-dropdown-filter, .p-select-filter')
            .first();
        if (await filterInput.isVisible({ timeout: 2_000 }).catch(() => false)) {
            await filterInput.fill(PREFIX);
        }

        await expect(
            page.locator('[role="option"]:visible', { hasText: produitAvecStock }),
        ).toBeVisible({ timeout: 10_000 });
        await expect(
            page.locator('[role="option"]:visible', { hasText: produitSansStock }),
        ).toHaveCount(0);
    });
});
