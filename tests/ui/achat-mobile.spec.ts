import { expect, test, type Page } from '@playwright/test';
import {
    BinaryBitmap,
    HybridBinarizer,
    QRCodeReader,
    RGBLuminanceSource,
} from '@zxing/library';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

async function decodeQr(
    image: import('@playwright/test').Locator,
): Promise<string> {
    await expect(image).toBeVisible();
    const { pixels, width, height } = await image.evaluate(
        async (element: HTMLImageElement) => {
            await element.decode();
            const canvas = document.createElement('canvas');
            canvas.width = element.naturalWidth;
            canvas.height = element.naturalHeight;
            const context = canvas.getContext('2d')!;
            context.drawImage(element, 0, 0);
            const rgba = context.getImageData(
                0,
                0,
                canvas.width,
                canvas.height,
            ).data;
            const pixels = Array.from(
                { length: canvas.width * canvas.height },
                (_, index) =>
                    (rgba[index * 4] +
                        2 * rgba[index * 4 + 1] +
                        rgba[index * 4 + 2]) /
                    4,
            );
            return { pixels, width: canvas.width, height: canvas.height };
        },
    );
    return new QRCodeReader()
        .decode(
            new BinaryBitmap(
                new HybridBinarizer(
                    new RGBLuminanceSource(
                        Uint8ClampedArray.from(pixels),
                        width,
                        height,
                    ),
                ),
            ),
        )
        .getText();
}

const base = {
    id: '01ACHATUI000000000000000001',
    reference: 'BC-091026-003',
    statut: 'receptionnee',
    statut_label: 'Réceptionnée',
    total_commande: 3000,
    fournisseur_nom: 'Topaz',
    site_nom: 'Matoto',
    site_payeur_nom: null,
    prochaine_action: null,
    statut_facture: 'impayee',
    statut_facture_label: 'Impayée',
    created_at: '09/10/2026',
    date_achat: '09/10/2026',
    qte_commandee: 1,
    qte_recue: 1,
    is_annulee: false,
    supprimable: false,
    annulable: false,
    peut_valider: false,
};

async function preview(
    page: Page,
    options: { empty?: boolean; canCreate?: boolean } = {},
) {
    const manifest = JSON.parse(
        await readFile('public/build/manifest.json', 'utf8'),
    );
    const entry = manifest['resources/js/app.ts'];
    const records = options.empty
        ? []
        : [
              base,
              {
                  ...base,
                  id: '01ACHATUI000000000000000002',
                  reference: 'BC-091026-002',
                  fournisseur_nom:
                      'Fournisseur avec un nom particulièrement long pour la consultation mobile',
                  statut_facture: 'payee',
                  statut_facture_label: 'Payée',
                  total_commande: 123456789000,
              },
              {
                  ...base,
                  id: '01ACHATUI000000000000000003',
                  reference: 'BC-091026-001',
                  statut: 'a_valider',
                  statut_label: 'À valider',
                  statut_facture: null,
                  statut_facture_label: null,
                  qte_recue: 0,
                  peut_valider: true,
              },
          ];
    const savedView = {
        id: '01ACHATVIEW000000000000001',
        name: 'Achats Topaz',
        visibility: 'personal',
        filters: {
            recherche: 'Topaz',
            site_ids: ['matoto'],
            statut: 'receptionnee',
        },
        owner_name: 'Aperçu UI',
        can_manage: true,
    };
    const initialPage = {
        component: 'Achats/Index',
        url: '/backoffice/achats',
        version: null,
        props: {
            saved_view: null,
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
                voit_toutes_agences: true,
                permissions: {
                    'achats.read': true,
                    'achats.create': true,
                    'achats.valider': true,
                },
            },
            theme: {
                active: { preset: 'aura', primary: 'blue', surface: 'slate' },
                allowed: {},
                locked: {},
            },
            peut_creer: options.canCreate ?? true,
            commandes: {
                data: records,
                total: records.length,
                last_page: 1,
                links: [],
            },
            filters: { site_ids: [] as string[] },
            statuts: [
                { value: 'a_valider', label: 'À valider' },
                { value: 'receptionnee', label: 'Réceptionnée' },
            ],
            fournisseurs: [{ value: 'topaz', label: 'Topaz' }],
            sites: [
                { id: 'matoto', nom: 'Matoto' },
                { id: 'tombolia', nom: 'Tombolia' },
            ],
        },
    };
    const data = JSON.stringify(initialPage)
        .replaceAll('&', '&amp;')
        .replaceAll('"', '&quot;')
        .replaceAll('<', '&lt;');
    const html =
        '<!doctype html><html lang="fr"><head><meta name="viewport" content="width=device-width,initial-scale=1">' +
        entry.css
            .map(
                (file: string) =>
                    '<link rel="stylesheet" href="/build/' + file + '">',
            )
            .join('') +
        '</head><body><div id="app" data-page="' +
        data +
        '"></div><script type="module" src="/build/' +
        entry.file +
        '"></script></body></html>';
    const publicRoot = path.resolve('public');
    await page.route('**/*', async (route) => {
        const url = new URL(route.request().url());
        if (url.pathname === '/backoffice/saved-filters/achats') {
            return route.fulfill({
                contentType: 'application/json',
                body: JSON.stringify({
                    views: [savedView],
                    default_id: null,
                    can_share: false,
                }),
            });
        }
        if (url.pathname === '/backoffice/achats') {
            if (route.request().headers()['x-inertia']) {
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
                            filters: url.searchParams.has('saved_view')
                                ? savedView.filters
                                : {
                                      recherche:
                                          url.searchParams.get('recherche') ??
                                          '',
                                      site_ids:
                                          url.searchParams.getAll('site_ids[]'),
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
        const file = path.resolve(publicRoot, '.' + url.pathname);
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
    await page.goto('http://ui-preview.test/backoffice/achats');
}

for (const [width, appearance] of [
    [360, 'light'],
    [432, 'dark'],
] as const) {
    test(
        'Achats mobile : cartes et filtres à ' +
            width +
            'px (' +
            appearance +
            ')',
        async ({ page }, testInfo) => {
            await page.setViewportSize({ width, height: 802 });
            await page.addInitScript(
                (value) => localStorage.setItem('appearance', value),
                appearance,
            );
            const errors: string[] = [];
            const mutations: string[] = [];
            page.on('pageerror', (error) => errors.push(error.message));
            page.on('request', (request) => {
                if (request.method() !== 'GET') mutations.push(request.url());
            });
            await preview(page);
            const list = page.locator('[aria-label="Liste des achats"]');
            const card = page.getByRole('button', {
                name: 'BC-091026-003, Réceptionnée. Voir le bon de commande',
                exact: true,
            });
            await expect(card).toBeVisible();
            await expect(card).toHaveAttribute('aria-haspopup', 'dialog');
            for (const label of [
                'Topaz',
                'Matoto',
                '3 000 GNF',
                '09/10/2026',
                'Reçu 1 / 1',
                'Facture : Impayée',
            ])
                await expect(card).toContainText(label);
            await expect(list.locator('button')).toHaveCount(3);
            await expect(page.getByRole('table')).toBeHidden();
            await expect(
                page.getByRole('textbox', { name: 'Rechercher un achat' }),
            ).toBeVisible();
            const toolbar = page.getByLabel('Recherche et vues des achats', {
                exact: true,
            });
            await expect(
                toolbar.getByRole('button', { name: 'Mes vues', exact: true }),
            ).toBeVisible();
            const header = page.locator('div.sticky').first();
            const searchBox = await toolbar
                .getByRole('textbox', { name: 'Rechercher un achat' })
                .boundingBox();
            const viewsBox = await toolbar
                .getByRole('button', { name: 'Mes vues', exact: true })
                .boundingBox();
            const filtersBox = await toolbar
                .getByRole('button', { name: 'Filtres', exact: true })
                .boundingBox();
            const headerBox = await header.boundingBox();
            expect(searchBox!.y).toBeGreaterThan(
                headerBox!.y + headerBox!.height,
            );
            expect(searchBox!.x).toBeLessThan(viewsBox!.x);
            expect(viewsBox!.x).toBeLessThan(filtersBox!.x);
            expect(Math.abs(searchBox!.y - filtersBox!.y)).toBeLessThan(2);
            await expect(
                header.getByRole('button', { name: 'Filtres', exact: true }),
            ).toHaveCount(0);
            await expect
                .poll(() =>
                    page.evaluate(
                        () =>
                            document.documentElement.scrollWidth <= innerWidth,
                    ),
                )
                .toBe(true);
            await expect(
                page.getByRole('link', { name: 'Nouveau', exact: true }),
            ).toBeVisible();
            await page.screenshot({
                path: testInfo.outputPath('achats-mobile.png'),
                fullPage: true,
            });
            await card.click();
            const details = page.getByRole('dialog', {
                name: base.reference,
                exact: true,
            });
            await expect(details).toBeVisible();
            await expect(page).toHaveURL(
                'http://ui-preview.test/backoffice/achats',
            );
            await expect(details).toContainText('Topaz');
            await expect(details).toContainText('Bon de commande fournisseur');
            const qr = details
                .getByRole('group', {
                    name: 'QR code du bon de commande ' + base.reference,
                })
                .getByRole('img');
            expect(await decodeQr(qr)).toBe(
                'http://ui-preview.test/backoffice/achats/' + base.id,
            );
            await expect(
                details.getByRole('button', {
                    name: /valider|modifier|annuler|payer/i,
                }),
            ).toHaveCount(0);
            await expect
                .poll(() =>
                    details.evaluate((el) => el.scrollWidth <= el.clientWidth),
                )
                .toBe(true);
            await expect
                .poll(() =>
                    details.evaluate((el) => el.getBoundingClientRect().height),
                )
                .toBeLessThanOrEqual(802 * 0.85 + 1);
            await page.screenshot({
                path: testInfo.outputPath('achats-details.png'),
                fullPage: true,
                animations: 'disabled',
            });
            const total = details.getByRole('heading', {
                name: 'Total commande',
            });
            await total.scrollIntoViewIfNeeded();
            await expect(total).toBeInViewport();
            await expect(details).toContainText('Impayée');
            await page.keyboard.press('Escape');
            await expect(details).toBeHidden();
            await expect(card).toBeFocused();
            const secondCard = page.getByRole('button', {
                name: 'BC-091026-002, Réceptionnée. Voir le bon de commande',
                exact: true,
            });
            await secondCard.click();
            const secondDetails = page.getByRole('dialog', {
                name: 'BC-091026-002',
                exact: true,
            });
            await expect(secondDetails).toBeVisible();
            await expect(secondDetails).toContainText(
                'Fournisseur avec un nom particulièrement long',
            );
            await expect(secondDetails).toContainText('123 456 789 000 GNF');
            await expect(secondDetails).not.toContainText('Impayée');
            await secondDetails
                .getByRole('button', { name: 'Fermer les détails' })
                .click();
            await expect(secondDetails).toBeHidden();
            await expect(secondCard).toBeFocused();
            await card.focus();
            await page.keyboard.press('Enter');
            await expect(details).toBeVisible();
            await page.mouse.click(5, 5);
            await expect(details).toBeHidden();
            await expect(card).toBeFocused();
            await expect(page).toHaveURL(
                'http://ui-preview.test/backoffice/achats',
            );
            await page
                .getByRole('button', { name: 'Filtres', exact: true })
                .click();
            const drawer = page.getByTestId('filters-drawer');
            await expect(drawer).toBeVisible();
            const labels = await drawer.locator('label').allTextContents();
            expect(labels.map((label) => label.trim())).toEqual([
                'Agence',
                'Statut',
                'Recherche',
                'Fournisseur',
                'À valider par moi',
            ]);
            await drawer
                .getByPlaceholder('Référence, fournisseur…')
                .fill('BC-091026-003');
            const agency = drawer.getByTestId('agency-filter');
            await agency.locator('.p-multiselect').click();
            await agency
                .getByRole('option', { name: 'Matoto', exact: true })
                .click();
            await agency.locator('.p-multiselect').click();
            const request = page.waitForRequest(
                (req) =>
                    req.method() === 'GET' &&
                    req.url().includes('recherche=BC-091026-003'),
            );
            await drawer.getByTestId('filters-apply').click();
            const query = new URL((await request).url()).searchParams;
            expect(query.get('recherche')).toBe('BC-091026-003');
            expect(query.getAll('site_ids[]')).toEqual(['matoto']);
            await expect(drawer).toBeHidden();
            const search = toolbar.getByRole('textbox', {
                name: 'Rechercher un achat',
            });
            await expect(search).toHaveValue('BC-091026-003');
            const searchRequest = page.waitForRequest(
                (req) =>
                    req.headers()['x-inertia'] === 'true' &&
                    req.url().includes('recherche=Topaz'),
            );
            await search.fill('Topaz');
            const searchQuery = new URL((await searchRequest).url())
                .searchParams;
            expect(searchQuery.getAll('site_ids[]')).toEqual(['matoto']);
            expect(searchQuery.get('all')).toBe('1');
            await expect(page).toHaveURL(/recherche=Topaz/);
            await toolbar
                .getByRole('button', { name: 'Mes vues', exact: true })
                .click();
            const applyView = page.waitForRequest(
                (req) =>
                    req.headers()['x-inertia'] === 'true' &&
                    req.url().includes('saved_view='),
            );
            await page.getByRole('button', { name: /^Achats Topaz/ }).click();
            expect(
                new URL((await applyView).url()).searchParams.get('saved_view'),
            ).toBe('01ACHATVIEW000000000000001');
            await expect(
                page.getByText('Vue : Achats Topaz').last(),
            ).toBeVisible();
            await expect(search).toHaveValue('Topaz');
            await search.fill('Autre');
            await expect(page).toHaveURL(/recherche=Autre/);
            const manual = new URL(page.url()).searchParams;
            expect(manual.get('saved_view')).toBeNull();
            expect(manual.get('statut')).toBe('receptionnee');
            expect(manual.getAll('site_ids[]')).toEqual(['matoto']);
            await expect(page.getByText('Vue : Achats Topaz')).toHaveCount(0);
            expect(mutations).toEqual([]);
            expect(errors).toEqual([]);
        },
    );
}

test('Achats : état vide sans autorisation de création', async ({ page }) => {
    await preview(page, { empty: true, canCreate: false });
    await expect(
        page.getByText('Aucun bon de commande trouvé.').last(),
    ).toBeVisible();
    await expect(page.getByRole('link', { name: /Nouveau/ })).toHaveCount(0);
    await expect(
        page.getByRole('button', { name: 'Filtres', exact: true }),
    ).toBeVisible();
});

test('Achats desktop : tableau et actions conservés', async ({ page }) => {
    await preview(page);
    await page
        .getByRole('button', {
            name: 'BC-091026-003, Réceptionnée. Voir le bon de commande',
            exact: true,
        })
        .click();
    await expect(
        page.getByRole('dialog', { name: base.reference, exact: true }),
    ).toBeVisible();
    await page.setViewportSize({ width: 1440, height: 900 });
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(page.getByRole('table')).toBeVisible();
    await expect(
        page.getByRole('heading', { name: 'Bons de commande fournisseurs' }),
    ).toBeVisible();
    await expect(
        page.getByRole('link', { name: 'Nouveau bon de commande' }),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: /BC-091026-003.*Voir le bon/ }),
    ).toBeHidden();
    await page.getByRole('button', { name: 'Filtres', exact: true }).click();
    await expect(page.getByTestId('filters-drawer')).toBeVisible();
});
