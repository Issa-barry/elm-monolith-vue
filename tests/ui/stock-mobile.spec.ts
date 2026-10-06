import { expect, test, type Page } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const stockUrl = '/backoffice/produits/stock';
const site = { id: 'site-kouria', nom: 'Kouria', code: '3' };
const savedView = {
    id: '01K6MSTOCKVIEW0000000000001',
    name: 'Bouteilles KOURIA',
    visibility: 'personal',
    filters: { search: 'Bouteille', site_ids: [site.id] },
    owner_name: 'Aperçu UI',
    can_manage: true,
};
const row = {
    produit_id: 'produit-1500',
    produit_nom: 'Pack Bouteille de 1500ml',
    image_url: '/storage/produits/stock-preview.svg',
    categorie_id: 'bouteilles',
    categorie_nom: 'Bouteilles',
    variante_id: 'variante-1500',
    variante_libelle: 'Variante par défaut',
    is_default: true,
    sku: '100003',
    site_id: site.id,
    site_nom: site.nom,
    site_code: site.code,
    qte_disponible: 99455,
    qte_physique: 99555,
    qte_engagee: 100,
    qte_bloquee: null,
    qte_entrante: null,
    seuil_effectif: 1000,
    disponible_sur_site: true,
    statut: 'disponible',
    statut_label: 'Disponible',
    dernier_mouvement: {
        type: 'sortie',
        quantite: 540,
        motif_label: 'Vente — CMD-031026-002',
        date: '03/10/2026',
    },
    can_ajuster: true,
};
const rows = [
    row,
    {
        ...row,
        produit_id: 'produit-350',
        produit_nom: 'Pack Bouteille de 350ml',
        variante_id: 'variante-350',
        sku: '100001',
        image_url: null,
        qte_disponible: 0,
        qte_physique: 400,
        qte_engagee: 400,
        statut: 'rupture',
        statut_label: 'Rupture',
        dernier_mouvement: null,
        can_ajuster: false,
    },
    {
        ...row,
        produit_id: 'produit-long',
        produit_nom:
            'Pack de bouteilles avec un nom particulièrement long pour la livraison',
        variante_id: 'variante-long',
        image_url: '/storage/produits/introuvable.jpg',
        variante_libelle:
            'Conditionnement avec une description particulièrement longue',
        qte_disponible: -5,
        qte_physique: 95,
        qte_engagee: 100,
        statut: 'stock_negatif',
        statut_label: 'Stock négatif',
        site_nom: 'Agence avec un nom particulièrement long',
        can_ajuster: false,
    },
];

const movement = {
    id: 'mouvement-vente',
    type: 'sortie',
    quantite: 540,
    stock_avant: 100095,
    stock_apres: 99555,
    notes: 'Livraison des commandes de la journée.',
    motif_type: 'vente',
    motif_label: 'Vente — CMD-031026-002',
    site_nom: site.nom,
    site_code: site.code,
    createur_nom: 'Moussa SIDIBÉ',
    date: '03/10/2026',
    created_at: '04/10/2026 08:35',
};
const history = {
    ajustements: [
        movement,
        {
            ...movement,
            id: 'mouvement-achat',
            type: 'entree',
            quantite: 1000,
            motif_type: 'apres_achat',
            motif_label:
                'Après achat — Bon fournisseur avec une référence longue',
            stock_avant: 99095,
            stock_apres: 100095,
        },
        ...Array.from({ length: 10 }, (_, index) => ({
            ...movement,
            id: `mouvement-${index}`,
        })),
    ],
    modifications: [
        {
            id: 'audit-produit',
            event_code: 'updated',
            event_label: 'Modification',
            actor_name: 'Moussa SIDIBÉ',
            old_values: {
                nom: row.produit_nom,
                description: 'Ancienne description du conditionnement',
            },
            new_values: {
                nom: 'Pack de bouteilles avec un nom particulièrement long pour la livraison',
                description:
                    'Description détaillée du nouveau conditionnement pour les agences de distribution.',
            },
            created_at: '05/10/2026 09:20',
        },
    ],
    motifs_disponibles: [
        { value: 'vente', label: 'Vente' },
        { value: 'apres_achat', label: 'Après achat' },
    ],
};

async function mountStock(
    page: Page,
    appearance = 'light',
    empty = false,
    stockRows = rows,
) {
    const manifest = JSON.parse(
        await readFile('public/build/manifest.json', 'utf8'),
    );
    const entry = manifest['resources/js/app.ts'];
    const initialPage = {
        component: 'Produits/Stock/Index',
        url: stockUrl,
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
                roles: ['super_admin'],
                sites: [],
                permissions: { 'produits.read': true },
            },
            theme: {
                active: { preset: 'aura', primary: 'blue', surface: 'slate' },
                allowed: {},
                locked: {},
            },
            stocks: {
                data: empty ? [] : stockRows,
                links: [],
                current_page: 1,
                last_page: 1,
                from: empty ? null : 1,
                to: empty ? null : stockRows.length,
                total: empty ? 0 : stockRows.length,
            },
            sites: [site, { id: 'site-matoto', nom: 'Matoto', code: '2' }],
            categories: [{ id: 'bouteilles', nom: 'Bouteilles' }],
            stock_statuts: [
                { value: 'disponible', label: 'Disponible' },
                { value: 'rupture', label: 'Rupture' },
                { value: 'stock_negatif', label: 'Stock négatif' },
            ],
            filters: {
                search: 'Bouteille',
                site_ids: [site.id],
                stock_statut: null,
                categorie_id: null,
            },
            saved_view: savedView,
            can_augmenter_stock: true,
            can_diminuer_stock: true,
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
        const request = route.request();
        const url = new URL(request.url());
        if (url.pathname === stockUrl) {
            if (request.headers()['x-inertia']) {
                return route.fulfill({
                    contentType: 'application/json',
                    headers: { 'X-Inertia': 'true' },
                    body: JSON.stringify({
                        ...initialPage,
                        url: url.pathname + url.search,
                        props: {
                            ...initialPage.props,
                            saved_view: url.searchParams.has('saved_view')
                                ? savedView
                                : null,
                            filters: {
                                ...initialPage.props.filters,
                                search: url.searchParams.get('search') ?? '',
                            },
                        },
                    }),
                });
            }
            return route.fulfill({
                contentType: 'text/html; charset=utf-8',
                body: html,
            });
        }
        if (url.pathname === '/backoffice/saved-filters/stock') {
            return route.fulfill({
                json: {
                    views: [savedView],
                    default_id: savedView.id,
                    can_share: true,
                },
            });
        }
        if (
            url.pathname === `/backoffice/produits/${row.produit_id}/historique`
        ) {
            return route.fulfill({
                json: {
                    ...history,
                    ajustements: history.ajustements.filter(
                        (m) =>
                            !url.searchParams.has('motif') ||
                            m.motif_type === url.searchParams.get('motif'),
                    ),
                },
            });
        }
        if (
            url.pathname ===
            `/backoffice/produits/${row.produit_id}/ajuster-stock`
        ) {
            return route.fulfill({
                contentType: 'application/json',
                headers: { 'X-Inertia': 'true' },
                body: JSON.stringify(initialPage),
            });
        }
        if (url.pathname === row.image_url) {
            return route.fulfill({
                contentType: 'image/svg+xml',
                body: '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120"><rect width="120" height="120" fill="#e0f2fe"/><rect x="44" y="30" width="32" height="72" rx="9" fill="#38bdf8"/><rect x="48" y="18" width="24" height="16" rx="3" fill="#2563eb"/><rect x="44" y="58" width="32" height="24" fill="white"/></svg>',
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
    await page.goto(`http://ui-preview.test${stockUrl}`);
}

test('la fiche conserve le site et les quantités de la ligne sélectionnée', async ({
    page,
}) => {
    await mountStock(page, 'light', false, [
        ...rows,
        {
            ...row,
            site_id: 'site-matoto',
            site_nom: 'Matoto',
            site_code: '2',
            qte_disponible: 7,
            qte_physique: 10,
            qte_engagee: 3,
        },
    ]);
    const details = await openStockDetails(page, 3);
    await expect(details).toContainText('Matoto (2)');
    const quantity = details
        .locator('dl div')
        .filter({ has: page.getByText('Physique', { exact: true }) });
    await expect(quantity.locator('dd')).toHaveText('10');
    const request = page.waitForRequest((req) =>
        req.url().includes('/historique?'),
    );
    await details.getByTestId('stock-history-button').click();
    const url = new URL((await request).url());
    expect(url.searchParams.get('variante_id')).toBe(row.variante_id);
    expect(url.searchParams.get('site_id')).toBe('site-matoto');
});

async function openStockDetails(page: Page, index = 0) {
    const details = page.getByRole('dialog', {
        name: 'Détails du stock',
        exact: true,
    });
    if (!(await details.isVisible()))
        await page.getByTestId('stock-card').nth(index).click();
    await expect(details).toBeVisible();
    return details;
}

async function openStockAction(page: Page, action: 'history' | 'adjust') {
    const details = await openStockDetails(page);
    await details.getByTestId(`stock-${action}-button`).click();
    await expect(details).toBeHidden();
}

for (const width of [360, 390, 440]) {
    test(`stock mobile lisible à ${width}px`, async ({ page }, testInfo) => {
        await page.setViewportSize({ width, height: 844 });
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await mountStock(page);
        await expect(page.getByTestId('stock-mobile-header')).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'Retour aux produits' }),
        ).toHaveAttribute('href', '/backoffice/produits');
        await expect(page.getByTestId('stock-table')).toBeHidden();
        const cards = page.getByTestId('stock-card');
        await expect(cards).toHaveCount(3);
        await expect(cards.first().getByTestId('stock-available')).toHaveText(
            '99 455',
        );
        await expect(cards.first()).toContainText(site.nom);
        await expect(cards.first()).not.toContainText('SKU');
        await expect(cards.first()).not.toContainText('Physique');
        await expect(cards.first()).not.toContainText(
            row.dernier_mouvement.motif_label,
        );
        await expect(cards.first().locator('img')).toBeVisible();
        await expect(
            cards.nth(1).locator('[data-slot="avatar-fallback"]'),
        ).toBeVisible();
        await expect(
            cards.nth(2).locator('[data-slot="avatar-fallback"]'),
        ).toBeVisible();
        await expect(
            cards.nth(1).getByTestId('stock-adjust-button'),
        ).toHaveCount(0);
        await expect(cards.nth(2).getByTestId('stock-available')).toHaveText(
            '-5',
        );
        for (const button of [
            page.getByRole('button', { name: 'Mes vues', exact: true }),
            page.getByRole('button', { name: /^Filtres/ }),
            page.getByRole('button', { name: 'Retirer la vue active' }),
            cards.first(),
        ]) {
            const box = await button.boundingBox();
            expect(box?.height).toBeGreaterThanOrEqual(44);
        }
        const viewsBox = await page
            .getByRole('button', { name: 'Mes vues', exact: true })
            .boundingBox();
        const filtersBox = await page
            .getByRole('button', { name: /^Filtres/ })
            .boundingBox();
        expect(viewsBox?.y).toBe(filtersBox?.y);
        await expect
            .poll(() =>
                page.evaluate(
                    () => document.documentElement.scrollWidth <= innerWidth,
                ),
            )
            .toBe(true);
        await page.screenshot({
            path: testInfo.outputPath('stock.png'),
            fullPage: true,
        });
        const details = await openStockDetails(page);
        await expect(
            details.getByRole('img', { name: row.produit_nom }),
        ).toBeVisible();
        await expect(details.locator('dl dd')).toHaveText([
            'Variante par défaut',
            '100003',
            'Bouteilles',
            '99 555',
            '100',
            '—',
            '—',
            '1 000',
        ]);
        await expect(details).toContainText(row.dernier_mouvement.motif_label);
        await expect
            .poll(async () => {
                const box = await details.boundingBox();
                return Math.abs(box!.y + box!.height - 844);
            })
            .toBeLessThan(2);
        for (const action of ['history', 'adjust']) {
            const button = details.getByTestId(`stock-${action}-button`);
            await expect(button).toBeInViewport();
            const box = await button.boundingBox();
            expect(box?.height).toBeGreaterThanOrEqual(44);
        }
        await expect
            .poll(() =>
                details.evaluate((el) => el.scrollWidth <= el.clientWidth),
            )
            .toBe(true);
        const drawerBox = await details.boundingBox();
        expect(drawerBox?.height).toBeLessThanOrEqual(844 * 0.85 + 1);
        await page.screenshot({
            path: testInfo.outputPath('details-stock.png'),
        });
        await details
            .getByRole('button', { name: 'Fermer les détails' })
            .click();
        await expect(cards.first()).toBeFocused();
        await openStockDetails(page, 1);
        await expect(details).toContainText('Rupture');
        await expect(details.getByTestId('stock-adjust-button')).toHaveCount(0);
        await page.keyboard.press('Escape');
        await expect(cards.nth(1)).toBeFocused();
        expect(errors).toEqual([]);
    });
}

test('historique et ajustement gardent la variante, le site et le stock physique', async ({
    page,
}, testInfo) => {
    await mountStock(page);
    const historyRequest = page.waitForRequest((request) =>
        request.url().includes('/historique?'),
    );
    await openStockAction(page, 'history');
    const url = new URL((await historyRequest).url());
    expect(url.searchParams.get('variante_id')).toBe(row.variante_id);
    expect(url.searchParams.get('site_id')).toBe(site.id);
    await expect(
        page.getByRole('dialog', {
            name: 'Historique du stock',
            exact: true,
        }),
    ).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('.stock-mobile-details')).toBeVisible();
    await openStockAction(page, 'adjust');
    const dialog = page.getByRole('dialog', {
        name: 'Ajuster le stock',
        exact: true,
    });
    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveCSS('opacity', '1');
    await expect(dialog).toContainText('99 555');
    await expect(dialog).toContainText('Kouria (3)');
    const box = await dialog.boundingBox();
    expect(box?.x).toBeGreaterThanOrEqual(0);
    expect((box?.x ?? 0) + (box?.width ?? 0)).toBeLessThanOrEqual(390);
    await page.screenshot({
        path: testInfo.outputPath('ajustement.png'),
        fullPage: true,
    });
});

test('filtres et vues enregistrées restent pilotés par les requêtes serveur', async ({
    page,
}) => {
    await mountStock(page);
    await page.getByRole('button', { name: /^Filtres/ }).click();
    await expect(page.getByTestId('agency-filter')).toBeVisible();
    await page.getByPlaceholder('Nom ou référence…').fill('350ml');
    const searchRequest = page.waitForRequest(
        (request) =>
            request.url().includes(stockUrl + '?') &&
            request.headers()['x-inertia'] === 'true',
    );
    await page.getByTestId('filters-apply').click();
    const url = new URL((await searchRequest).url());
    expect(url.searchParams.get('search')).toBe('350ml');
    expect(url.searchParams.getAll('site_ids[]')).toEqual([site.id]);
    await expect(page.getByTestId('filters-drawer')).toBeHidden();
    await page.getByRole('button', { name: 'Mes vues', exact: true }).click();
    const savedRequest = page.waitForRequest((request) =>
        request.url().includes('saved_view='),
    );
    await page.getByRole('button', { name: /^Bouteilles KOURIA/ }).click();
    expect(
        new URL((await savedRequest).url()).searchParams.get('saved_view'),
    ).toBe(savedView.id);
    await expect(
        page.getByRole('button', { name: 'Retirer la vue active' }),
    ).toBeVisible();
    const resetRequest = page.waitForRequest(
        (request) => new URL(request.url()).searchParams.get('all') === '1',
    );
    await page.getByRole('button', { name: 'Retirer la vue active' }).click();
    expect(
        new URL((await resetRequest).url()).searchParams.has('saved_view'),
    ).toBe(false);
});

test('stock mobile en thème sombre', async ({ page }, testInfo) => {
    await mountStock(page, 'dark');
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expect(page.getByTestId('stock-card').first()).toBeVisible();
    await page.screenshot({
        path: testInfo.outputPath('stock-sombre.png'),
        fullPage: true,
    });
});

test('stock mobile en thème sombre et état vide', async ({
    page,
}, testInfo) => {
    await mountStock(page, 'dark', true);
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expect(page.getByTestId('stock-mobile-header')).toContainText(
        '0 résultat',
    );
    await expect(page.getByText('Aucun stock à afficher').last()).toBeVisible();
    await expect(page.getByRole('button', { name: /^Filtres/ })).toBeVisible();
    await page.screenshot({
        path: testInfo.outputPath('stock-vide-sombre.png'),
        fullPage: true,
    });
});

test('le tableau Stock reste disponible sur ordinateur', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await mountStock(page);
    await expect(page.getByTestId('stock-mobile-header')).toBeHidden();
    await expect(page.getByTestId('stock-table')).toBeVisible();
    await expect(page.getByTestId('stock-card').first()).toBeHidden();
    await expect(page.getByTestId('stock-table').getByRole('row')).toHaveCount(
        4,
    );
    await expect(page.getByRole('button', { name: /^Filtres/ })).toHaveCount(1);
});

for (const width of [360, 390, 440]) {
    test(`fenêtres Stock adaptées au mobile à ${width}px`, async ({
        page,
    }, testInfo) => {
        await page.setViewportSize({ width, height: 844 });
        await mountStock(page, width === 440 ? 'dark' : 'light');
        const card = page.getByTestId('stock-card').first();
        await openStockAction(page, 'adjust');
        const adjustment = page.getByRole('dialog', {
            name: 'Ajuster le stock',
            exact: true,
        });
        await expect(adjustment).toHaveCSS('opacity', '1');
        await expect(adjustment).toContainText(row.produit_nom);
        const dimensions = await adjustment.boundingBox();
        expect(dimensions?.x).toBe(0);
        expect(dimensions?.y).toBe(0);
        expect(dimensions?.width).toBe(width);
        expect(dimensions?.height).toBe(844);
        await expect(adjustment.getByLabel('Augmenter')).toHaveCSS(
            'font-size',
            '16px',
        );
        await page.screenshot({
            path: testInfo.outputPath('ajustement-mobile.png'),
        });
        // Fenêtre réduite : les champs défilent et les actions restent accessibles.
        await page.setViewportSize({ width, height: 500 });
        const submitBox = await adjustment
            .getByTestId('stock-submit-button')
            .boundingBox();
        expect(
            (submitBox?.y ?? 0) + (submitBox?.height ?? 0),
        ).toBeLessThanOrEqual(500);
        const scroll = adjustment.locator('.p-dialog-content');
        expect(
            await scroll.evaluate((el) => el.scrollHeight > el.clientHeight),
        ).toBe(true);
        await adjustment
            .getByRole('button', { name: 'Annuler', exact: true })
            .click();
        await expect(page.locator('.stock-mobile-details')).toBeVisible();
        await page.setViewportSize({ width, height: 844 });
        await openStockAction(page, 'history');
        const dialog = page.getByRole('dialog', {
            name: 'Historique du stock',
            exact: true,
        });
        await expect(dialog).toHaveCSS('opacity', '1');
        await expect(dialog.getByTestId('stock-movement-card')).toHaveCount(12);
        await expect(
            dialog.getByTestId('historique-motif-filter'),
        ).toContainText('Tous les motifs');
        await expect(
            dialog.getByTestId('stock-movement-card').first(),
        ).toContainText(movement.motif_label);
        await expect(
            dialog.getByTestId('stock-movement-card').first().locator('dd'),
        ).toHaveText(['100 095', '99 555']);
        await expect(dialog.locator('table').first()).toBeHidden();
        const content = dialog.locator('.p-dialog-content');
        expect(
            await content.evaluate((el) => el.scrollWidth <= el.clientWidth),
        ).toBe(true);
        await page.screenshot({
            path: testInfo.outputPath('historique-mobile.png'),
        });
        await content.evaluate((el) => {
            el.scrollTop = el.scrollHeight;
        });
        await expect(
            dialog.getByRole('button', { name: 'Fermer l’historique' }),
        ).toBeInViewport();
        await content.evaluate((el) => {
            el.scrollTop = 0;
        });
        await dialog.getByRole('tab', { name: /^Modifications/ }).click();
        const changes = dialog.getByTestId('stock-audit-changes');
        await expect(changes).toContainText(
            history.modifications[0].new_values.description,
        );
        expect(
            await content.evaluate((el) => el.scrollWidth <= el.clientWidth),
        ).toBe(true);
        await page.screenshot({
            path: testInfo.outputPath('modifications-mobile.png'),
        });
        await dialog
            .getByRole('button', { name: 'Fermer l’historique' })
            .click();
        await expect(dialog).toBeHidden();
        await expect(page.locator('.stock-mobile-details')).toBeVisible();
        await page.locator('.stock-mobile-details [autofocus]').click();
        await expect(card).toBeFocused();
    });
}

test('ajustement mobile : quantité, motif et soumission conservés', async ({
    page,
}) => {
    await mountStock(page);
    await openStockAction(page, 'adjust');
    const dialog = page.getByRole('dialog', {
        name: 'Ajuster le stock',
        exact: true,
    });
    await expect(dialog.getByTestId('stock-submit-button')).toBeDisabled();
    await dialog.getByLabel('Augmenter').fill('10');
    await dialog.getByTestId('stock-motif-select').click();
    await page
        .getByRole('option', { name: 'Après achat', exact: true })
        .click();
    await dialog.getByLabel('Diminuer').fill('5');
    await expect(dialog.getByLabel('Augmenter')).toHaveValue('');
    await expect(dialog.getByTestId('stock-submit-button')).toBeDisabled();
    await dialog.getByTestId('stock-motif-select').click();
    await page.getByRole('option', { name: 'Perte', exact: true }).click();
    await expect(dialog.getByTestId('stock-preview')).toContainText('99 550');
    const request = page.waitForRequest(
        (req) =>
            req.method() === 'POST' && req.url().endsWith('/ajuster-stock'),
    );
    await dialog.getByTestId('stock-submit-button').click();
    expect((await request).postDataJSON()).toMatchObject({
        site_id: site.id,
        variante_id: row.variante_id,
        augmenter: null,
        diminuer: 5,
        motif_type: 'perte',
    });
    await expect(dialog).toBeHidden();
});

test('le filtre de motif recharge l’historique de la bonne variante et agence', async ({
    page,
}) => {
    await mountStock(page);
    await openStockAction(page, 'history');
    const dialog = page.getByRole('dialog', {
        name: 'Historique du stock',
        exact: true,
    });
    await expect(dialog.getByTestId('stock-movement-card')).toHaveCount(12);
    await dialog.getByTestId('historique-motif-filter').click();
    const request = page.waitForRequest((req) =>
        req.url().includes('motif=apres_achat'),
    );
    await page
        .getByRole('option', { name: 'Après achat', exact: true })
        .click();
    const url = new URL((await request).url());
    expect(url.searchParams.get('variante_id')).toBe(row.variante_id);
    expect(url.searchParams.get('site_id')).toBe(site.id);
    await expect(dialog.getByTestId('stock-movement-card')).toHaveCount(1);
    await expect(dialog.getByTestId('stock-movement-card')).toContainText(
        '+1 000',
    );
});

test('les fenêtres Stock gardent leur tableau et leur format sur ordinateur', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await mountStock(page);
    const table = page.getByTestId('stock-table');
    await table.getByTestId('stock-history-button').first().click();
    const dialog = page.getByRole('dialog', {
        name: `${row.produit_nom} · ${row.variante_libelle} · ${row.site_nom}`,
        exact: true,
    });
    await expect(dialog.locator('table').first()).toBeVisible();
    await expect(
        dialog.getByTestId('stock-movement-card').first(),
    ).toBeHidden();
    await dialog.getByRole('button', { name: 'Fermer l’historique' }).click();
    await table.getByTestId('stock-adjust-button').first().click();
    const adjustment = page.getByRole('dialog', {
        name: 'Ajuster le stock',
        exact: true,
    });
    await expect(adjustment).toHaveCSS('opacity', '1');
    expect((await adjustment.boundingBox())?.width).toBe(512);
});

test('historique mobile : chargement et liste vide restent refermables', async ({
    page,
}) => {
    await mountStock(page);
    let release!: () => void;
    const pending = new Promise<void>((resolve) => {
        release = resolve;
    });
    await page.route(/\/historique\?/, async (route) => {
        await pending;
        await route.fulfill({
            json: {
                ajustements: [],
                modifications: [],
                motifs_disponibles: [],
            },
        });
    });
    await openStockAction(page, 'history');
    const dialog = page.getByRole('dialog', {
        name: 'Historique du stock',
        exact: true,
    });
    await expect(dialog.getByRole('status')).toContainText('Chargement');
    await expect(
        dialog.getByRole('button', { name: 'Fermer l’historique' }),
    ).toBeInViewport();
    release();
    await expect(dialog).toContainText('Aucun mouvement de stock enregistré.');
    await dialog.getByRole('button', { name: 'Fermer l’historique' }).click();
    await expect(dialog).toBeHidden();
});

test('un motif sans résultat peut être effacé dans l’historique', async ({
    page,
}) => {
    await mountStock(page);
    await page.route(/\/historique\?.*motif=apres_achat/, (route) =>
        route.fulfill({ json: { ...history, ajustements: [] } }),
    );
    await openStockAction(page, 'history');
    const dialog = page.getByRole('dialog', {
        name: 'Historique du stock',
        exact: true,
    });
    await dialog.getByTestId('historique-motif-filter').click();
    await page
        .getByRole('option', { name: 'Après achat', exact: true })
        .click();
    await expect(dialog).toContainText('Aucun mouvement pour ce motif.');
    await dialog.getByTestId('historique-motif-filter').click();
    await page
        .getByRole('option', { name: 'Tous les motifs', exact: true })
        .click();
    await expect(dialog.getByTestId('stock-movement-card')).toHaveCount(12);
});
