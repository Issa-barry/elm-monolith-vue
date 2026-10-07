import { expect, test, type Page } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

// Le vrai bundle, avec des factures fictives et sans acces a la base.
const factures = [
    {
        id: 1,
        reference: 'VTE-061026-003',
        vehicule_nom: null,
        client_nom: 'Thierno Habib',
        montant_net: 91250,
        montant_encaisse: 91250,
        montant_restant: 0,
        statut_facture: 'payee',
        statut_label: 'Payée',
        is_payee: true,
    },
    {
        id: 2,
        reference: 'VTE-061026-002',
        vehicule_nom: 'Abarry',
        client_nom: 'Thierno',
        montant_net: 9720000,
        montant_encaisse: 972000,
        montant_restant: 8748000,
        statut_facture: 'partiel',
        statut_label: 'Partiellement payée',
        is_payee: false,
    },
    {
        id: 3,
        reference: 'VTE-061026-001',
        vehicule_nom: 'Véhicule de livraison de la grande agence',
        client_nom: 'Client avec un nom particulièrement long',
        montant_net: 123456789,
        montant_encaisse: 12345678,
        montant_restant: 111111111,
        statut_facture: 'partiel',
        statut_label: 'Partiellement payée',
        is_payee: false,
    },
].map((facture) => ({
    ...facture,
    commande_id: facture.id,
    commande_reference: facture.reference,
    site_nom: 'Matoto',
    quantite_totale: 540,
    is_annulee: false,
    is_encaissable: true,
    peut_encaisser_especes: false,
    moyens_encaissement: [],
    encaissement_agences: null,
    created_at: '06/10/2026',
    encaissements: [],
}));

async function ouvrir(page: Page, dark = false) {
    const manifest = JSON.parse(
        await readFile('public/build/manifest.json', 'utf8'),
    );
    const entry = manifest['resources/js/app.ts'];
    const assets = entry.css
        .map((file: string) => `<link rel="stylesheet" href="/build/${file}">`)
        .join('');
    const data = {
        component: 'Factures/Index',
        url: '/backoffice/factures',
        version: null,
        props: {
            errors: {},
            flash: {},
            name: 'ELM',
            sidebarOpen: true,
            auth: {
                user: {
                    id: 'preview',
                    name: 'Aperçu UI',
                    email: 'preview@example.test',
                },
                roles: [],
                sites: [],
                permissions: { 'ventes.read': true, 'ventes.update': true },
            },
            theme: {
                active: { preset: 'aura', primary: 'blue', surface: 'slate' },
                allowed: {},
                locked: {},
            },
            factures,
            totaux: {
                total: 133268039,
                nb_total: 3,
                total_a_encaisser: 119859111,
                nb_impayees: 0,
                montant_impayees: 0,
                nb_partielles: 2,
                montant_partielles: 119859111,
                nb_payees: 1,
                montant_payees: 91250,
            },
            periode: 'tout',
            statut: 'tous',
            sites: [{ value: 'matoto', label: 'Matoto' }],
            site_ids: [],
        },
    };
    await page.addInitScript(
        (appearance) => localStorage.setItem('appearance', appearance),
        dark ? 'dark' : 'light',
    );
    const publicRoot = path.resolve('public');
    await page.route('**/*', async (route) => {
        const url = new URL(route.request().url());
        if (url.pathname.startsWith('/backoffice/saved-filters'))
            return route.fulfill({ json: { data: [] } });
        if (url.pathname === '/backoffice/factures') {
            const escaped = JSON.stringify(data)
                .replaceAll('&', '&amp;')
                .replaceAll('"', '&quot;')
                .replaceAll('<', '&lt;');
            return route.fulfill({
                contentType: 'text/html; charset=utf-8',
                body:
                    '<!doctype html><html lang="fr"><head><meta name="viewport" content="width=device-width,initial-scale=1">' +
                    assets +
                    '</head><body><div id="app" data-page="' +
                    escaped +
                    '"></div><script type="module" src="/build/' +
                    entry.file +
                    '"></script></body></html>',
            });
        }
        const file = path.resolve(publicRoot, '.' + url.pathname);
        if (!file.startsWith(publicRoot + path.sep)) return route.abort();
        try {
            return route.fulfill({
                contentType: file.endsWith('.js')
                    ? 'text/javascript'
                    : file.endsWith('.css')
                      ? 'text/css'
                      : 'application/octet-stream',
                body: await readFile(file),
            });
        } catch {
            return route.fulfill({ status: 404, body: '' });
        }
    });
    await page.goto('http://ui-preview.test/backoffice/factures');
    await expect(page.getByTestId('factures-table')).toBeAttached();
}

for (const width of [1920, 1440, 1024]) {
    test(`colonnes lisibles et defilement local a ${width}px`, async ({
        page,
    }, testInfo) => {
        await page.setViewportSize({ width, height: 1080 });
        await ouvrir(page);
        const table = page.getByTestId('factures-table');
        await expect(
            table.getByRole('cell', { name: '123 456 789 GNF', exact: true }),
        ).toBeVisible();
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
        const rows = table.locator('tbody tr');
        for (const row of await rows.all()) {
            for (const index of [3, 4, 5, 6]) {
                const cell = row.locator('td').nth(index);
                await expect(cell).toHaveCSS('text-align', 'right');
                const lineCount = await cell
                    .locator('span')
                    .evaluate((span) => {
                        const range = document.createRange();
                        range.selectNodeContents(span);
                        return range.getClientRects().length;
                    });
                expect(lineCount).toBe(1);
            }
        }
        for (const name of ['Qté', 'Montant', 'Encaissé', 'Restant']) {
            await expect(
                table
                    .getByRole('columnheader', { name, exact: true })
                    .locator('[data-pc-section="columnheadercontent"]'),
            ).toHaveCSS('justify-content', 'flex-end');
        }
        const scroller = table.locator('[data-pc-section="tablecontainer"]');
        if (width === 1920) {
            expect(
                await scroller.evaluate(
                    (el) => el.scrollWidth <= el.clientWidth,
                ),
            ).toBe(true);
        } else {
            expect(
                await scroller.evaluate(
                    (el) => el.scrollWidth > el.clientWidth,
                ),
            ).toBe(true);
            await scroller.evaluate((el) => {
                el.scrollLeft = el.scrollWidth;
            });
            await expect(
                table.getByRole('button', {
                    name: 'Actions de la facture VTE-061026-003',
                }),
            ).toBeInViewport();
            await scroller.evaluate((el) => {
                el.scrollLeft = 0;
            });
        }
        await page.screenshot({ path: testInfo.outputPath('colonnes.png') });
        await table
            .getByRole('columnheader', { name: 'Montant', exact: true })
            .click();
        await expect(rows.first()).toContainText('VTE-061026-003');
        await table
            .getByRole('columnheader', { name: 'Montant', exact: true })
            .click();
        await expect(rows.first()).toContainText('VTE-061026-001');
        await table
            .getByRole('button', {
                name: 'Actions de la facture VTE-061026-001',
            })
            .click();
        await page.getByRole('menuitem', { name: 'Historique' }).click();
        await expect(page.getByRole('dialog')).toContainText(
            'Historique — VTE-061026-001',
        );
    });
}

test('tableau lisible en mode sombre', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 1920, height: 1080 });
    await ouvrir(page, true);
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expect(page.getByTestId('factures-table')).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath('colonnes-sombre.png') });
});

test('les cartes restent visibles sur mobile sans debordement', async ({
    page,
}) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await ouvrir(page);
    await expect(page.getByTestId('factures-table')).toBeHidden();
    await expect(
        page.getByText('VTE-061026-003', { exact: true }).first(),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Encaisser', exact: true }).first(),
    ).toBeVisible();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
});
