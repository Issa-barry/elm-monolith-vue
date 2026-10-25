import { expect, test, type Page } from '@playwright/test';
import { closeFilterDrawerIfOpen, login } from './helpers';

// Barre rapide du tableau des ventes (docs/filters.md, « Barre rapide du tableau ») :
// recherche + N° commande, Livreur, Véhicule au-dessus du tableau, panneau « Filtres » inchangé
// dans l'en-tête, filtrage serveur piloté par l'URL.

test.setTimeout(120_000);

test.beforeEach(async ({ page }) => {
    await closeFilterDrawerIfOpen(page);
});

// La page rend une vue mobile (sm:hidden) et une vue ordinateur : on vise la barre visible.
function barre(page: Page) {
    return page.locator('[data-testid="quick-filters"]:visible').first();
}

function champRapide(page: Page, cle: string) {
    return barre(page).getByTestId(`quick-filter-${cle}`);
}

async function ouvrirVentes(page: Page, query = '') {
    await login(page);
    await page.goto(`/backoffice/ventes${query}`);
    await expect(barre(page)).toBeVisible({ timeout: 15_000 });
}

async function sansDebordementHorizontal(page: Page) {
    const deborde = await page.evaluate(
        () =>
            document.documentElement.scrollWidth >
            document.documentElement.clientWidth + 1,
    );
    expect(deborde).toBe(false);
}

test.describe('Ventes — barre rapide du tableau', () => {
    test('affiche la recherche et les filtres rapides par défaut, le bouton garde le libellé « Filtres »', async ({
        page,
    }) => {
        await ouvrirVentes(page);

        await expect(champRapide(page, 'recherche')).toHaveAttribute(
            'placeholder',
            'Rechercher une vente…',
        );
        await expect(champRapide(page, 'numero_commande')).toBeVisible();
        await expect(champRapide(page, 'livreur')).toBeVisible();
        await expect(champRapide(page, 'vehicule')).toBeVisible();

        const bouton = page
            .getByTestId('list-page-actions')
            .getByRole('button', { name: /^Filtres/ });
        await expect(bouton).toBeVisible();
        await expect(bouton).toHaveText(/^\s*Filtres\s*\d*\s*$/);

        // Le panneau ne répète pas les champs de la barre rapide.
        await bouton.click();
        const panneau = page.getByTestId('filters-drawer');
        await expect(panneau.getByTestId('filter-field-statuts')).toBeVisible();
        await expect(panneau.getByTestId('filter-field-client')).toBeVisible();
        for (const cle of [
            'recherche',
            'numero_commande',
            'livreur',
            'vehicule',
        ]) {
            await expect(
                panneau.getByTestId(`filter-field-${cle}`),
            ).toHaveCount(0);
        }
    });

    test('combine un filtre rapide et un critère du panneau, puis efface l’un sans l’autre', async ({
        page,
    }) => {
        await ouvrirVentes(page, '?statuts[]=brouillon&all=1');

        await champRapide(page, 'numero_commande').fill('VTE');
        await champRapide(page, 'numero_commande').press('Enter');
        await expect(page).toHaveURL(/numero_commande=VTE/, {
            timeout: 10_000,
        });
        await expect(page).toHaveURL(/statuts/);

        // Après rechargement, la valeur est restituée depuis l'URL.
        await page.reload();
        await expect(champRapide(page, 'numero_commande')).toHaveValue('VTE');

        await barre(page)
            .getByTestId('quick-filter-clear-numero_commande')
            .click();
        await expect(page).not.toHaveURL(/numero_commande/, {
            timeout: 10_000,
        });
        await expect(page).toHaveURL(/statuts/);
    });

    test('la recherche est envoyée au serveur et un résultat vide est expliqué', async ({
        page,
    }) => {
        await ouvrirVentes(page);

        await champRapide(page, 'recherche').fill('ZZZ-INTROUVABLE-42');
        await expect(page).toHaveURL(/recherche=ZZZ-INTROUVABLE-42/, {
            timeout: 10_000,
        });
        await expect(
            page.getByText('Aucune commande trouvée.').locator('visible=true'),
        ).toBeVisible();

        await barre(page).getByTestId('quick-filters-reset').click();
        await expect(page).not.toHaveURL(/recherche=/, { timeout: 10_000 });
    });

    for (const [nom, largeur, hauteur] of [
        ['mobile', 390, 844],
        ['tablette', 820, 1180],
        ['ordinateur', 1440, 900],
    ] as const) {
        test(`reste utilisable sans débordement horizontal (${nom})`, async ({
            page,
        }) => {
            await page.setViewportSize({ width: largeur, height: hauteur });
            await ouvrirVentes(page);

            for (const cle of [
                'recherche',
                'numero_commande',
                'livreur',
                'vehicule',
            ]) {
                const champ = champRapide(page, cle);
                await expect(champ).toBeVisible();
                const boite = await champ.boundingBox();
                expect(boite!.width).toBeGreaterThan(80);
                expect(boite!.x + boite!.width).toBeLessThanOrEqual(largeur);
            }
            await expect(
                page
                    .getByRole('button', { name: /^Filtres/ })
                    .locator('visible=true')
                    .first(),
            ).toBeVisible();
            await sansDebordementHorizontal(page);
        });
    }
});
