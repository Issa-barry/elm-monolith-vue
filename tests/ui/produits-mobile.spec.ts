import { expect, test, type Page } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const product = {
    id: 'produit-1500',
    nom: 'Pack Bouteille de 1500ml',
    sku: '100003',
    code_barres: null,
    produit_type_id: 'fabricable',
    type_nom: 'Fabricable',
    image_url: null,
    statut: 'actif',
    statut_label: 'Actif',
    prix_usine: 1000,
    prix_vente: 1200,
    prix_achat: null,
    cout: 500,
    qte_stock: 100,
    description: null,
    in_stock: true,
    is_low_stock: false,
    is_out_of_stock: false,
    has_stock: true,
    is_used: false,
    has_variantes: false,
    last_mouvement_type: null,
    last_mouvement_quantite: null,
    stocks_par_site: [],
};

async function mountProducts(page: Page, appearance = 'light') {
    const manifest = JSON.parse(
        await readFile('public/build/manifest.json', 'utf8'),
    );
    const entry = manifest['resources/js/app.ts'];
    const savedView = {
        id: 'vue-produits',
        name: 'Bouteilles disponibles pour toutes les agences',
        visibility: 'personal',
        filters: { produit_type_id: 'fabricable' },
        owner_name: 'Aperçu UI',
        can_manage: true,
    };
    const initialPage = {
        component: 'Produits/Index',
        url: '/backoffice/produits',
        version: null,
        props: {
            errors: {},
            flash: {},
            name: 'ELM',
            sidebarOpen: false,
            auth: {
                user: {
                    id: 'preview',
                    name: 'Aperçu UI',
                    email: 'preview@example.test',
                },
                roles: [],
                sites: [],
                permissions: { 'produits.read': true, 'produits.create': true },
            },
            theme: {
                active: { preset: 'aura', primary: 'blue', surface: 'slate' },
                allowed: {},
                locked: {},
            },
            produits: [
                product,
                {
                    ...product,
                    id: 'produit-350',
                    nom: 'Pack Bouteille de 350ml',
                    sku: '100001',
                },
            ],
            sites: [],
            types: [{ value: 'fabricable', label: 'Fabricable' }],
            categories: [],
            statuts: [{ value: 'actif', label: 'Actif' }],
            filters: savedView.filters,
            saved_view: savedView,
            can_ajuster_stock: false,
        },
    };
    const data = JSON.stringify(initialPage)
        .replaceAll('&', '&amp;')
        .replaceAll('"', '&quot;')
        .replaceAll('<', '&lt;');
    const html = `<!doctype html><html lang="fr"><head><meta name="viewport" content="width=device-width,initial-scale=1">${entry.css.map((file: string) => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><div id="app" data-page="${data}"></div><script type="module" src="/build/${entry.file}"></script></body></html>`;
    const publicRoot = path.resolve('public');
    await page.addInitScript(
        (value) => localStorage.setItem('appearance', value),
        appearance,
    );
    await page.route('**/*', async (route) => {
        const url = new URL(route.request().url());
        if (url.pathname === '/backoffice/produits') {
            return route.fulfill({
                contentType: 'text/html; charset=utf-8',
                body: html,
            });
        }
        if (url.pathname === '/backoffice/saved-filters/produits') {
            return route.fulfill({
                json: {
                    views: [savedView],
                    default_id: savedView.id,
                    can_share: false,
                },
            });
        }
        const file = path.resolve(publicRoot, `.${url.pathname}`);
        if (!file.startsWith(publicRoot + path.sep)) return route.abort();
        try {
            await route.fulfill({
                contentType: file.endsWith('.js')
                    ? 'text/javascript'
                    : file.endsWith('.css')
                      ? 'text/css'
                      : 'application/octet-stream',
                body: await readFile(file),
            });
        } catch {
            await route.fulfill({ status: 404, body: '' });
        }
    });
    await page.goto('http://ui-preview.test/backoffice/produits');
}

for (const width of [360, 390, 413, 440]) {
    test(`recherche Produits sur une ligne dédiée à ${width}px`, async ({
        page,
    }, testInfo) => {
        await page.setViewportSize({ width, height: 802 });
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await mountProducts(page);
        const search = page.getByRole('searchbox', {
            name: 'Rechercher un produit',
        });
        await expect(search).toBeVisible();
        const searchBox = (await search.boundingBox())!;
        expect(searchBox.width).toBeGreaterThanOrEqual(width - 34);
        expect(searchBox.height).toBeGreaterThanOrEqual(44);
        const views = page.getByRole('button', {
            name: 'Mes vues',
            exact: true,
        });
        const filters = page.getByRole('button', { name: /^Filtres/ });
        const viewsBox = (await views.boundingBox())!;
        const filtersBox = (await filters.boundingBox())!;
        expect(viewsBox.y).toBeGreaterThanOrEqual(
            searchBox.y + searchBox.height,
        );
        expect(filtersBox.y).toBe(viewsBox.y);
        expect(viewsBox.height).toBeGreaterThanOrEqual(44);
        expect(filtersBox.height).toBeGreaterThanOrEqual(44);
        await expect(
            page.getByRole('button', { name: 'Retirer la vue active' }),
        ).toBeVisible();
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
        await page.screenshot({
            path: testInfo.outputPath('produits-mobile.png'),
            animations: 'disabled',
        });
        await search.fill('100001');
        await expect(
            page.getByRole('link', { name: /Pack Bouteille de 350ml/ }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: /Pack Bouteille de 1500ml/ }),
        ).toBeHidden();
        await search.fill('');
        await expect(
            page.getByRole('link', { name: /Pack Bouteille de 1500ml/ }),
        ).toBeVisible();
        await filters.click();
        await expect(
            page.getByRole('dialog', { name: 'Filtres', exact: true }),
        ).toBeVisible();
        expect(errors).toEqual([]);
    });
}

test('recherche Produits lisible en thème sombre', async ({
    page,
}, testInfo) => {
    await mountProducts(page, 'dark');
    const search = page.getByRole('searchbox', {
        name: 'Rechercher un produit',
    });
    await expect(search).toBeVisible();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.screenshot({
        path: testInfo.outputPath('produits-mobile-dark.png'),
        animations: 'disabled',
    });
});

test('la liste Produits conserve son en-tête sur ordinateur', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await mountProducts(page);
    await expect(
        page.getByRole('searchbox', { name: 'Rechercher un produit' }),
    ).toBeHidden();
    await expect(
        page.getByRole('button', { name: 'Nouveau produit', exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Exporter Excel', exact: true }),
    ).toBeVisible();
    await expect(page.getByRole('table')).toBeVisible();
});
