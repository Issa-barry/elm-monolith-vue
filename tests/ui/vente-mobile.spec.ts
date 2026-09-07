import { expect, test, type Locator } from '@playwright/test';
import {
    BinaryBitmap,
    HybridBinarizer,
    QRCodeReader,
    RGBLuminanceSource,
} from '@zxing/library';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

async function decodeQr(image: Locator): Promise<string> {
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
    const source = new RGBLuminanceSource(
        Uint8ClampedArray.from(pixels),
        width,
        height,
    );
    return new QRCodeReader()
        .decode(new BinaryBitmap(new HybridBinarizer(source)))
        .getText();
}

const base = {
    id: '01VENTEUI000000000000000001',
    reference: 'VTE-070926-001',
    statut: 'livree',
    statut_label: 'Livrée',
    nature_operation: 'vente_standard',
    processus_code: 'transfert_grossiste',
    processus_label: 'Transfert grossiste',
    created_at: '07/09/2026',
    vehicule_nom: 'Camion Alpha',
    vehicule_immatriculation: 'RC-1234-A',
    vehicule_photo_url: '/storage/vehicules/ui-preview.svg',
    chauffeur_nom: 'Chauffeur exemple',
    client_nom: 'Client exemple',
    client_telephone: '+224622123456',
    site_nom: 'Agence exemple',
    total_commande: 10260000,
    facture_id: '01FACTUREUI000000000000001',
    facture_statut: 'partiel',
    facture_statut_label: 'Partiellement payée',
    facture_montant_encaisse: 260000,
    facture_montant_restant: 10000000,
    encaissements: [],
    can_modifier: true,
    can_confirmer: false,
    can_annuler: false,
    is_annulee: false,
    is_brouillon: false,
};

test.beforeEach(async ({ page }) => {
    const manifest = JSON.parse(
        await readFile('public/build/manifest.json', 'utf8'),
    );
    const entry = manifest['resources/js/app.ts'];
    const initialPage = {
        component: 'Ventes/Index',
        url: '/backoffice/ventes',
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
                permissions: {
                    'ventes.create': true,
                    'ventes.read': true,
                    'ventes.update': true,
                    'encaissements.create': true,
                },
            },
            theme: {
                active: { preset: 'aura', primary: 'blue', surface: 'slate' },
                allowed: {},
                locked: {},
            },
            commandes: [
                base,
                {
                    ...base,
                    id: '01VENTEUI000000000000000002',
                    reference: 'VTE-070926-002',
                    vehicule_nom: null,
                    vehicule_immatriculation: null,
                    vehicule_photo_url: null,
                    client_nom: null,
                    client_telephone: null,
                    chauffeur_nom: null,
                    processus_code: 'vente',
                    processus_label: 'Vente',
                    statut: 'brouillon',
                    statut_label: 'Brouillon',
                    facture_id: null,
                    facture_statut: null,
                    facture_statut_label: null,
                    facture_montant_encaisse: null,
                    facture_montant_restant: null,
                },
                {
                    ...base,
                    id: '01VENTEUI000000000000000003',
                    reference: 'VTE-070926-003',
                    vehicule_nom:
                        'Véhicule de livraison avec un nom particulièrement long',
                    vehicule_photo_url: '/storage/vehicules/introuvable.jpg',
                    statut: 'chargement_en_cours',
                    statut_label: 'Chargement en cours',
                },
            ],
            totaux: {
                total_montant: 30780000,
                total_a_encaisser: 30000000,
                deja_paye: 780000,
                nb_total: 3,
                nb_cloturees: 0,
                montant_cloturees: 0,
            },
            nature_filtree: 'vente_standard',
            page_title: 'Ventes',
            periode: '',
            statuts_actifs: [],
            sites: [],
            is_admin: false,
            can_creer_commande: true,
            raison_blocage_commande: null,
            filters: { site_ids: [] },
        },
    };
    const data = JSON.stringify(initialPage)
        .replaceAll('&', '&amp;')
        .replaceAll('"', '&quot;')
        .replaceAll('<', '&lt;');
    const html = `<!doctype html><html lang="fr"><head><meta name="viewport" content="width=device-width,initial-scale=1">${entry.css.map((file: string) => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><div id="app" data-page="${data}"></div><script type="module" src="/build/${entry.file}"></script></body></html>`;
    const publicRoot = path.resolve('public');
    await page.route('**/*', async (route) => {
        const pathname = new URL(route.request().url()).pathname;
        if (pathname === '/backoffice/ventes')
            return route.fulfill({
                contentType: 'text/html; charset=utf-8',
                body: html,
            });
        if (pathname === base.vehicule_photo_url)
            return route.fulfill({
                contentType: 'image/svg+xml',
                body: '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 80 80"><rect width="80" height="80" fill="#e2e8f0"/><path d="M12 24h34v29H12zm34 10h12l10 12v7H46z" fill="#64748b"/><circle cx="24" cy="56" r="7" fill="#334155"/><circle cx="57" cy="56" r="7" fill="#334155"/></svg>',
            });
        const file = path.resolve(publicRoot, `.${pathname}`);
        if (!file.startsWith(publicRoot + path.sep)) return route.abort();
        try {
            const contentType = file.endsWith('.js')
                ? 'text/javascript'
                : file.endsWith('.css')
                  ? 'text/css'
                  : 'application/octet-stream';
            await route.fulfill({ contentType, body: await readFile(file) });
        } catch {
            await route.fulfill({ status: 404, body: '' });
        }
    });
});

for (const width of [360, 390, 480]) {
    test(`consultation mobile à ${width}px : carte, fiche, fermeture et focus`, async ({
        page,
    }, testInfo) => {
        await page.setViewportSize({ width, height: 844 });
        const mutations: string[] = [];
        const errors: string[] = [];
        page.on('request', (request) => {
            if (request.method() !== 'GET') mutations.push(request.url());
        });
        page.on('pageerror', (error) => errors.push(error.message));
        await page.goto('http://ui-preview.test/backoffice/ventes');
        const card = page.getByRole('button', {
            name: 'VTE-070926-001, Livrée. Voir les détails',
            exact: true,
        });
        await expect(card).toBeVisible();
        for (const label of [
            'Camion Alpha',
            'RC-1234-A',
            'Transfert grossiste',
            '07/09/2026',
        ])
            await expect(card).toContainText(label);
        await expect(card.locator('img')).toBeVisible();
        await expect(
            page
                .getByRole('button', {
                    name: /VTE-070926-003.*Voir les détails/,
                })
                .locator('[data-slot="avatar-fallback"]'),
        ).toBeVisible();
        await expect
            .poll(() =>
                page.evaluate(
                    () => document.documentElement.scrollWidth <= innerWidth,
                ),
            )
            .toBe(true);
        await page.screenshot({
            path: testInfo.outputPath('liste.png'),
            fullPage: true,
        });

        await card.click();
        const drawer = page.getByRole('dialog', { name: base.reference });
        await expect(drawer).toBeVisible();
        const header = drawer.locator('.p-drawer-header');
        const statusBox = await header
            .getByText('Livrée', { exact: true })
            .boundingBox();
        const titleBox = await header
            .getByRole('heading', { name: base.reference })
            .boundingBox();
        expect(statusBox!.x).toBeGreaterThan(titleBox!.x + titleBox!.width);
        expect(Math.abs(statusBox!.y - titleBox!.y)).toBeLessThan(4);
        const qr = drawer
            .getByRole('group', {
                name: `QR code de la commande ${base.reference}`,
            })
            .getByRole('img');
        expect(await decodeQr(qr)).toBe(base.reference);
        await expect
            .poll(() =>
                drawer.evaluate((el) => el.getBoundingClientRect().height),
            )
            .toBeGreaterThan(500);
        await expect(page).toHaveURL(
            'http://ui-preview.test/backoffice/ventes',
        );
        await expect(drawer).toContainText('Client exemple');
        await expect(drawer).toContainText('10 260 000 GNF');
        await expect(drawer).toContainText('10 000 000 GNF');
        await expect(
            drawer.getByRole('button', { name: /encaisser|modifier|annuler/i }),
        ).toHaveCount(0);
        await expect
            .poll(() =>
                drawer.evaluate((el) => el.scrollWidth <= el.clientWidth),
            )
            .toBe(true);
        await expect
            .poll(async () => {
                const box = await drawer.boundingBox();
                return Math.abs(box!.y + box!.height - 844);
            })
            .toBeLessThan(2);
        await page.screenshot({
            path: testInfo.outputPath('fiche.png'),
            fullPage: true,
            animations: 'disabled',
        });
        await drawer
            .getByText('Restant à payer', { exact: true })
            .scrollIntoViewIfNeeded();
        await expect(
            drawer.getByText('Restant à payer', { exact: true }),
        ).toBeInViewport();
        await page.keyboard.press('Escape');
        await expect(drawer).toBeHidden();
        await expect(card).toBeFocused();

        const noVehicle = page.getByRole('button', {
            name: /VTE-070926-002.*Voir les détails/,
        });
        await expect(noVehicle).toContainText('Sans véhicule');
        await noVehicle.click();
        const second = page.getByRole('dialog', { name: 'VTE-070926-002' });
        await expect(second).toContainText('Sans véhicule');
        expect(
            await decodeQr(
                second
                    .getByRole('group', {
                        name: 'QR code de la commande VTE-070926-002',
                    })
                    .getByRole('img'),
            ),
        ).toBe('VTE-070926-002');
        await expect(second).not.toContainText('Camion Alpha');
        await expect(second).not.toContainText('Restant à payer');
        await second
            .getByRole('button', { name: 'Fermer les détails' })
            .click();
        await expect(second).toBeHidden();
        await expect(noVehicle).toBeFocused();
        await card.click();
        await expect(drawer).toBeVisible();
        await page.mouse.click(5, 5);
        await expect(drawer).toBeHidden();
        await expect(card).toBeFocused();
        expect(mutations).toEqual([]);
        expect(errors).toEqual([]);
    });
}

test('le passage au bureau ferme la fiche et conserve le tableau', async ({
    page,
}) => {
    await page.goto('http://ui-preview.test/backoffice/ventes');
    await page
        .getByRole('button', { name: /VTE-070926-001.*Voir les détails/ })
        .click();
    await expect(
        page.getByRole('dialog', { name: base.reference }),
    ).toBeVisible();
    for (const width of [768, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        await expect(page.getByRole('dialog')).toHaveCount(0);
        await expect(page.getByRole('table').first()).toBeVisible();
        await expect(
            page.getByRole('button', {
                name: /VTE-070926-001.*Voir les détails/,
            }),
        ).toBeHidden();
        await expect
            .poll(() =>
                page.evaluate(
                    () => document.documentElement.scrollWidth <= innerWidth,
                ),
            )
            .toBe(true);
    }
});
