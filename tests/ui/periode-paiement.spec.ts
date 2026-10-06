import { expect, test, type Page } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const url = '/backoffice/comptabilite/periodes/preview';
const vehicules = [
    {
        vehicule_id: 'adama',
        vehicule_nom: 'ADAMA',
        vehicule_immat: 'OU114',
        type_vehicule_id: 'camion',
        type_vehicule_nom: 'Camion',
        nb_membres: 1,
        taille_equipe: 2,
        nb_commandes: 1,
        theorique: 60_000,
        ajuste: 60_000,
        ecart: 0,
        equilibre: true,
        deja_paye: 0,
        reste: 60_000,
        statut_validation: 'a_verifier',
    },
    {
        vehicule_id: 'abarry',
        vehicule_nom: 'Abarry',
        vehicule_immat: 'AI3462',
        type_vehicule_id: 'tricycle',
        type_vehicule_nom: 'Tricycle',
        nb_membres: 1,
        taille_equipe: null,
        nb_commandes: 1,
        theorique: 216_000,
        ajuste: 216_000,
        ecart: 0,
        equilibre: true,
        deja_paye: 0,
        reste: 216_000,
        statut_validation: 'a_verifier',
    },
];

// Vrai bundle, réponses interceptées : aucun paiement ni accès à la base locale.
async function ouvrir(page: Page, overrides: Record<string, unknown> = {}) {
    const manifest = JSON.parse(
        await readFile('public/build/manifest.json', 'utf8'),
    );
    const entry = manifest['resources/js/app.ts'];
    const initialPage = {
        component: 'Comptabilite/Periodes/Show',
        url,
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
                roles: ['super_admin'],
                sites: [],
                is_admin: true,
                permissions: { 'comptabilite.read': true },
            },
            theme: {
                active: { preset: 'aura', primary: 'blue', surface: 'slate' },
                allowed: {},
                locked: {},
            },
            periode: {
                id: 'preview',
                reference: 'PAY-202610-P1-LIV',
                type: 'livreur',
                type_label: 'Livreurs',
                site: null,
                date_debut: '2026-10-01',
                date_fin: '2026-10-15',
                statut: 'calculee',
                statut_label: 'Calculée',
                observations: null,
                nb_fiches: 2,
                total_net: 276_000,
                total_paye: 0,
            },
            vehicules,
            typesVehicule: [
                { value: 'camion', label: 'Camion' },
                { value: 'tricycle', label: 'Tricycle' },
            ],
            beneficiaires: [],
            filters: {},
            recalcul: { effectue: false, nb_fiches: 2 },
            validation: {
                possible: true,
                raison: null,
                commissions_hors_fiches: { nombre: 0, montant: 0 },
            },
            stats: {
                total_brut: 276_000,
                total_net: 276_000,
                total_paye: 0,
                reste: 276_000,
            },
            can: {
                calculer: true,
                valider: true,
                cloturer: true,
                delete: true,
                ajuster: true,
            },
            ...overrides,
        },
    };
    const data = JSON.stringify(initialPage)
        .replaceAll('&', '&amp;')
        .replaceAll('"', '&quot;')
        .replaceAll('<', '&lt;');
    const html = `<!doctype html><html lang="fr"><head><meta name="viewport" content="width=device-width,initial-scale=1">${entry.css.map((file: string) => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><div id="app" data-page="${data}"></div><script type="module" src="/build/${entry.file}"></script></body></html>`;
    const publicRoot = path.resolve('public');
    await page.route('**/*', async (route) => {
        const request = route.request();
        const pathname = new URL(request.url()).pathname;
        if (pathname.startsWith(url)) {
            if (request.headers()['x-inertia']) {
                return route.fulfill({
                    contentType: 'application/json',
                    headers: { 'X-Inertia': 'true' },
                    body: JSON.stringify({
                        ...initialPage,
                        url: request.url(),
                        props: {
                            ...initialPage.props,
                            filters: Object.fromEntries(
                                new URL(request.url()).searchParams,
                            ),
                        },
                    }),
                });
            }
            return route.fulfill({
                contentType: 'text/html; charset=utf-8',
                body: html,
            });
        }
        const file = path.resolve(publicRoot, `.${pathname}`);
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
    await page.goto(`http://ui-preview.test${url}`);
}

for (const width of [390, 768, 1440, 1920]) {
    test(`montants, filtres et sélection à ${width}px`, async ({
        page,
    }, testInfo) => {
        await page.setViewportSize({ width, height: 1000 });
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await ouvrir(page);
        await expect(
            page.getByRole('heading', { name: 'Paiement des livreurs' }),
        ).toBeVisible();
        await expect(page.getByRole('progressbar')).toHaveAttribute(
            'aria-valuenow',
            '0',
        );
        const lignes = page.getByTestId(
            width < 768 ? 'vehicules-mobile' : 'vehicules-table',
        );
        await expect(lignes).toBeVisible();
        await expect(lignes.getByText(/OU114/).first()).toBeVisible();
        await expect(lignes.getByText(/Camion/).first()).toBeVisible();
        if (width >= 768)
            await expect(
                lignes.getByRole('columnheader', { name: 'Type de véhicule' }),
            ).toBeVisible();
        await lignes
            .getByRole('checkbox', { name: 'Sélectionner ADAMA', exact: true })
            .click();
        await expect(
            page.getByText('1 véhicule sélectionné', { exact: true }),
        ).toBeVisible();
        await lignes
            .getByRole('checkbox', { name: 'Sélectionner Abarry', exact: true })
            .click();
        await expect(
            page.getByRole('button', {
                name: 'Valider la sélection (2)',
                exact: true,
            }),
        ).toBeVisible();
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
        await page.screenshot({
            path: testInfo.outputPath('periode-selection.png'),
            fullPage: true,
        });
        await page
            .getByRole('button', { name: 'Désélectionner', exact: true })
            .click();
        await expect(
            page.getByRole('button', { name: /^Valider la sélection/ }),
        ).toHaveCount(0);
        const recherche = page.getByRole('textbox', {
            name: 'Rechercher un véhicule',
            exact: true,
        });
        await expect(recherche).toBeVisible();
        await recherche.fill('ADAMA');
        const rechercheRequest = page.waitForRequest(
            (r) => new URL(r.url()).searchParams.get('vehicule') === 'ADAMA',
        );
        await recherche.press('Enter');
        await rechercheRequest;
        const typeVehicule = page.getByTestId('periode-type-vehicule');
        await expect(typeVehicule).toBeVisible();
        await typeVehicule.locator('.p-select-dropdown').click();
        const typeRequest = page.waitForRequest(
            (r) =>
                new URL(r.url()).searchParams.get('type_vehicule_id') ===
                'camion',
        );
        await page.getByRole('option', { name: 'Camion', exact: true }).click();
        expect(
            new URL((await typeRequest).url()).searchParams.get('vehicule'),
        ).toBe('ADAMA');
        await expect(typeVehicule).toContainText('Camion');
        await page.getByRole('button', { name: /^Filtres/ }).click();
        const drawer = page.getByRole('dialog', { name: 'Filtres' });
        await expect(drawer).toBeVisible();
        await expect(
            drawer.getByPlaceholder('Nom ou immatriculation…'),
        ).toHaveCount(0);
        await expect(
            drawer.getByTestId('filter-field-type_vehicule_id'),
        ).toHaveCount(0);
        await drawer
            .getByTestId('filter-field-etat')
            .locator('.p-multiselect-dropdown')
            .click();
        await page
            .getByRole('option', { name: 'À vérifier', exact: true })
            .click();
        await page.keyboard.press('Escape');
        const request = page.waitForRequest(
            (request) =>
                new URL(request.url()).searchParams.get('etat') ===
                'a_verifier',
        );
        await drawer
            .getByRole('button', { name: 'Appliquer les filtres', exact: true })
            .click();
        const params = new URL((await request).url());
        expect(params.pathname).toBe(url);
        expect(params.searchParams.get('vehicule')).toBe('ADAMA');
        expect(params.searchParams.get('type_vehicule_id')).toBe('camion');
        await expect(drawer).not.toBeVisible();
        await expect(
            page.getByText('0 / 2 véhicules affichés validés'),
        ).toBeVisible();
        const effacerRequest = page.waitForRequest(
            (r) =>
                new URL(r.url()).pathname === url &&
                !new URL(r.url()).searchParams.has('vehicule'),
        );
        await page
            .getByRole('button', { name: 'Effacer la recherche', exact: true })
            .click();
        expect(
            new URL((await effacerRequest).url()).searchParams.get(
                'type_vehicule_id',
            ),
        ).toBe('camion');
        await expect(recherche).toHaveValue('');
        const clearTypeRequest = page.waitForRequest(
            (r) =>
                new URL(r.url()).pathname === url &&
                !new URL(r.url()).searchParams.has('type_vehicule_id'),
        );
        await typeVehicule.locator('[data-pc-section="clearicon"]').click();
        expect(
            new URL((await clearTypeRequest).url()).searchParams.get('etat'),
        ).toBe('a_verifier');
        expect(errors).toEqual([]);
    });
}

test('validation simple et groupée conservent les identifiants envoyés', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await ouvrir(page);
    const table = page.getByTestId('vehicules-table');
    const adama = table.locator('tbody tr').filter({ hasText: 'ADAMA' });
    const single = page.waitForRequest(
        (r) =>
            r.method() === 'POST' &&
            r.url().endsWith('/ajustements/valider-vehicules'),
    );
    await adama.getByRole('button', { name: 'Valider', exact: true }).click();
    expect((await single).postDataJSON()).toEqual({ vehicules: ['adama'] });
    await expect(
        adama.getByRole('button', { name: 'Valider', exact: true }),
    ).toBeEnabled();
    await table
        .getByRole('checkbox', {
            name: 'Sélectionner tous les véhicules à valider',
        })
        .click();
    const bulk = page.waitForRequest(
        (r) =>
            r.method() === 'POST' &&
            r.url().endsWith('/ajustements/valider-vehicules'),
    );
    await page
        .getByRole('button', { name: 'Valider la sélection (2)', exact: true })
        .click();
    expect((await bulk).postDataJSON()).toEqual({
        vehicules: ['adama', 'abarry'],
    });
});

test('blocage serveur visible, véhicule déséquilibré et exports accessibles', async ({
    page,
}) => {
    await ouvrir(page, {
        vehicules: [{ ...vehicules[0], equilibre: false }],
        validation: {
            possible: false,
            raison: 'Une commission reste à répartir.',
            commissions_hors_fiches: { nombre: 0, montant: 0 },
        },
    });
    await expect(
        page.getByText('Une commission reste à répartir.', { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Valider la période', exact: true }),
    ).toBeDisabled();
    const mobile = page.getByTestId('vehicules-mobile');
    await expect(mobile.getByRole('checkbox')).toBeDisabled();
    await expect(
        mobile.getByRole('button', { name: 'Valider', exact: true }),
    ).toBeDisabled();
    await expect(
        mobile.getByRole('link', { name: 'Ajuster', exact: true }),
    ).toHaveAttribute('href', `${url}/ajustements/vehicules/adama`);
    await page.getByRole('button', { name: 'Exporter', exact: true }).click();
    await expect(
        page.getByRole('menuitem', { name: 'Télécharger le PDF' }),
    ).toBeVisible();
    await expect(
        page.getByRole('menuitem', { name: 'Télécharger Excel' }),
    ).toBeVisible();
    await page.keyboard.press('Escape');
    await page.getByRole('button', { name: 'Actions', exact: true }).click();
    await page.getByRole('menuitem', { name: 'Clôturer la période' }).click();
    await expect(
        page.getByText(
            'Clôturer cette période ? Elle sera archivée définitivement.',
        ),
    ).toBeVisible();
});

test('lecture seule et bénéficiaires sans notion de véhicule', async ({
    page,
}) => {
    await ouvrir(page, {
        periode: {
            id: 'preview',
            reference: 'PAY-SAL',
            type: 'salarie',
            type_label: 'Salariés',
            site: null,
            date_debut: '2026-10-01',
            date_fin: '2026-10-15',
            statut: 'validee',
            statut_label: 'Validée',
            observations: null,
        },
        beneficiaires: [
            {
                fiche_id: 'fiche-1',
                beneficiaire_nom: 'Fatou',
                montant_brut: 276_000,
                montant_net: 276_000,
                montant_paye: 0,
                reste: 276_000,
                statut: 'a_payer',
                statut_label: 'À payer',
            },
        ],
        can: {
            calculer: false,
            valider: false,
            cloturer: false,
            delete: false,
            ajuster: false,
        },
    });
    await expect(
        page.getByRole('heading', { name: 'Paiement des salariés' }),
    ).toBeVisible();
    await expect(
        page.getByRole('heading', { name: 'Bénéficiaires' }),
    ).toBeVisible();
    await expect(page.getByText('Fatou', { exact: true })).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Actions', exact: true }),
    ).toHaveCount(0);
    await expect(
        page.getByRole('button', { name: 'Valider la période', exact: true }),
    ).toHaveCount(0);
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
});
