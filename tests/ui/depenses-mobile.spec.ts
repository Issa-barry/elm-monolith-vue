import { expect, test, type Page } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const base = {
    id: 'depense-validee',
    montant: 5000,
    date_depense: '2026-09-06',
    statut: 'valide',
    statut_label: 'Validée',
    commentaire: 'Avance de septembre',
    type: {
        id: 'type-avance',
        libelle: 'Avance sur salaire',
        categorie: 'livreur',
        categorie_label: 'Livreur',
    },
    beneficiaire_type: 'livreur',
    beneficiaire_id: 'livreur-saa',
    beneficiaire_label: 'Saa Fodé',
    beneficiaire_telephone: '+224613855281',
    vehicule_id: null,
    vehicule_nom: null,
    vehicule_immatriculation: null,
    site: { id: 'site-matoto', nom: 'Matoto' },
    user: { id: 'agent', name: 'Aperçu UI' },
    validateur: { id: 'validateur', name: 'Validateur UI' },
    can_valider: false,
};
const rows = [
    base,
    {
        ...base,
        id: 'depense-soumise',
        statut: 'soumis',
        statut_label: 'Soumise',
        can_valider: true,
        montant: 1234567890,
        beneficiaire_label: 'Bénéficiaire avec un nom particulièrement long',
        site: {
            id: 'site-kouria',
            nom: 'Agence avec un nom particulièrement long pour le suivi des dépenses',
        },
    },
    {
        ...base,
        id: 'depense-brouillon',
        statut: 'brouillon',
        statut_label: 'Brouillon',
        commentaire: null,
        beneficiaire_label: null,
        type: null,
        site: null,
    },
];

async function mountExpenses(
    page: Page,
    options: { dark?: boolean; empty?: boolean; readonly?: boolean } = {},
) {
    const manifest = JSON.parse(
        await readFile('public/build/manifest.json', 'utf8'),
    );
    const entry = manifest['resources/js/app.ts'];
    const initialPage = {
        component: 'Depenses/Index',
        url: '/backoffice/depenses',
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
                roles: ['admin_entreprise'],
                sites: [],
                permissions: {
                    'depenses.read': true,
                    'depenses.create': !options.readonly,
                    'depenses.update': !options.readonly,
                    'depenses.delete': !options.readonly,
                },
            },
            theme: {
                active: { preset: 'aura', primary: 'blue', surface: 'slate' },
                allowed: {},
                locked: {},
            },
            depenses: {
                data: options.empty
                    ? []
                    : rows.map((d) => ({
                          ...d,
                          can_valider: options.readonly ? false : d.can_valider,
                      })),
                links: [
                    { url: null, label: '&laquo; Précédent', active: false },
                    {
                        url: '/backoffice/depenses?page=1',
                        label: '1',
                        active: true,
                    },
                    {
                        url: '/backoffice/depenses?page=2',
                        label: '2',
                        active: false,
                    },
                ],
                current_page: 1,
                last_page: 2,
                total: options.empty ? 0 : 3,
            },
            types: [
                {
                    id: 'type-avance',
                    libelle: 'Avance sur salaire',
                    categorie: 'livreur',
                },
            ],
            sites: [base.site, { id: 'site-kouria', nom: 'Kouria' }],
            categories: [{ value: 'livreur', label: 'Livreur' }],
            statuts: [
                { value: 'brouillon', label: 'Brouillon' },
                { value: 'soumis', label: 'Soumise' },
                { value: 'valide', label: 'Validée' },
            ],
            filters: {
                site_ids: [base.site.id],
                statut: '',
                search: '',
                date_debut: '2026-09-01',
            },
            stats: {
                total: options.empty ? 0 : 3,
                montant_total: options.empty ? 0 : 1234577890,
                en_attente: options.empty ? 0 : 1,
                validees: options.empty ? 0 : 1,
            },
            can_create: !options.readonly,
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
        options.dark ? 'dark' : 'light',
    );
    await page.route('**/*', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        if (
            url.pathname === '/backoffice/depenses' ||
            /^\/backoffice\/depenses\/depense-[^/]+\/(soumettre|valider|rejeter)$/.test(
                url.pathname,
            )
        ) {
            if (request.headers()['x-inertia']) {
                return route.fulfill({
                    contentType: 'application/json',
                    headers: { 'X-Inertia': 'true' },
                    body: JSON.stringify({
                        ...initialPage,
                        url: url.pathname + url.search,
                    }),
                });
            }
            return route.fulfill({
                contentType: 'text/html; charset=utf-8',
                body: html,
            });
        }
        if (url.pathname === '/backoffice/comptabilite/historique/entite')
            return route.fulfill({
                json: [
                    {
                        id: 'audit',
                        event_code: 'created',
                        event_label: 'Création',
                        actor_name: 'Aperçu UI',
                        module_label: 'Dépenses',
                        description:
                            'Dépense enregistrée avec un commentaire particulièrement long pour vérifier le retour à la ligne sur mobile.',
                        old_values: null,
                        new_values: null,
                        meta: null,
                        created_at: '06/09/2026 08:30',
                    },
                ],
            });
        const file = path.resolve(publicRoot, `.${url.pathname}`);
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
    await page.goto('http://ui-preview.test/backoffice/depenses');
}

for (const width of [360, 390, 413, 440]) {
    test(`liste Dépenses mobile à ${width}px`, async ({ page }, testInfo) => {
        await page.setViewportSize({ width, height: 802 });
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await mountExpenses(page);
        await expect(page.getByTestId('depenses-table')).toBeHidden();
        await expect(page.getByTestId('depense-card')).toHaveCount(3);
        const first = page.getByTestId('depense-card').first();
        await expect(first).toContainText('5 000 GNF');
        await expect(first).toContainText('06/09/2026');
        await expect(first).toContainText('Saa Fodé');
        await expect(first).toContainText('Matoto');
        await expect(first).toContainText('Validée');
        await expect(first.getByRole('link')).toHaveAttribute(
            'href',
            '/backoffice/depenses/depense-validee',
        );
        await expect(
            page.getByRole('button', { name: 'Nouvelle dépense', exact: true }),
        ).toHaveCount(1);
        for (const control of [
            page.getByRole('link', { name: 'Retour au tableau de bord' }),
            page.getByRole('button', { name: 'Nouvelle dépense', exact: true }),
            page.getByTestId('depenses-export-trigger'),
            page.getByRole('button', { name: /^Filtres/ }),
            first.getByRole('button', { name: 'Actions', exact: true }),
        ]) {
            expect(
                (await control.boundingBox())!.height,
            ).toBeGreaterThanOrEqual(44);
        }
        const newBox = (await page
            .getByRole('button', { name: 'Nouvelle dépense', exact: true })
            .boundingBox())!;
        const headingBox = (await page
            .getByRole('heading', { name: 'Dépenses', exact: true })
            .boundingBox())!;
        expect(
            Math.abs(
                newBox.y +
                    newBox.height / 2 -
                    headingBox.y -
                    headingBox.height / 2,
            ),
        ).toBeLessThan(20);
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
        await page.screenshot({
            path: testInfo.outputPath('depenses-mobile.png'),
            animations: 'disabled',
        });
        await first
            .getByRole('button', { name: 'Actions', exact: true })
            .click();
        await expect(
            page.getByRole('menuitem', { name: 'Voir le détail', exact: true }),
        ).toBeVisible();
        await expect(
            page.getByRole('menuitem', { name: 'Valider', exact: true }),
        ).toHaveCount(0);
        await expect(
            page.getByRole('menuitem', { name: 'Supprimer', exact: true }),
        ).toHaveCount(0);
        expect(errors).toEqual([]);
    });
}

test('actions mobiles : soumission et validation gardent le bon identifiant', async ({
    page,
}) => {
    await mountExpenses(page);
    for (const [id, action, endpoint] of [
        ['depense-brouillon', 'Soumettre', 'soumettre'],
        ['depense-soumise', 'Valider', 'valider'],
    ]) {
        const card = page.getByTestId('depense-card').filter({
            has: page.locator(`a[href="/backoffice/depenses/${id}"]`),
        });
        await card
            .getByRole('button', { name: 'Actions', exact: true })
            .click();
        const request = page.waitForRequest(
            (r) =>
                r.method() === 'PATCH' &&
                r.url().endsWith(`/${id}/${endpoint}`),
        );
        await page.getByRole('menuitem', { name: action, exact: true }).click();
        await request;
        await expect(
            page.getByRole('menuitem', { name: action, exact: true }),
        ).toBeHidden();
    }
});

test('rejet mobile : motif et commentaire restent requis', async ({
    page,
}, testInfo) => {
    await page.setViewportSize({ width: 360, height: 640 });
    await mountExpenses(page);
    await page
        .getByTestId('depense-card')
        .nth(1)
        .getByRole('button', { name: 'Actions' })
        .click();
    await page.getByRole('menuitem', { name: 'Rejeter', exact: true }).click();
    const dialog = page.getByRole('dialog', {
        name: 'Rejeter la dépense',
        exact: true,
    });
    await expect(dialog).toBeVisible();
    await dialog
        .getByRole('button', { name: 'Rejeter la dépense', exact: true })
        .click();
    await expect(dialog).toContainText('Le motif de rejet est obligatoire.');
    await dialog.getByLabel('Motif de rejet').selectOption('Autre');
    await dialog
        .getByRole('button', { name: 'Rejeter la dépense', exact: true })
        .click();
    await expect(dialog).toContainText('Le commentaire est obligatoire');
    await dialog.getByLabel('Commentaire').fill('Justificatif manquant');
    await expect(
        dialog.getByRole('button', { name: 'Rejeter la dépense', exact: true }),
    ).toBeInViewport();
    await page.screenshot({
        path: testInfo.outputPath('rejet-mobile.png'),
        animations: 'disabled',
    });
    const request = page.waitForRequest(
        (r) =>
            r.url().endsWith('/depense-soumise/rejeter') &&
            r.method() === 'PATCH',
    );
    await dialog
        .getByRole('button', { name: 'Rejeter la dépense', exact: true })
        .click();
    expect((await request).postDataJSON()).toEqual({
        motif_rejet: 'Autre',
        commentaire_rejet: 'Justificatif manquant',
    });
    await expect(dialog).toBeHidden();
});

test('historique mobile : largeur et bonne dépense', async ({
    page,
}, testInfo) => {
    await mountExpenses(page);
    await page
        .getByTestId('depense-card')
        .first()
        .getByRole('button', { name: 'Actions' })
        .click();
    const request = page.waitForRequest((r) =>
        r.url().includes('/historique/entite?'),
    );
    await page
        .getByRole('menuitem', { name: 'Historique', exact: true })
        .click();
    const params = new URL((await request).url()).searchParams;
    expect(params.get('auditable_id')).toBe('depense-validee');
    expect(params.get('auditable_type')).toBe('App\\Models\\Depense');
    expect(params.get('module')).toBe('depenses');
    const dialog = page.getByRole('dialog');
    await expect(dialog).toContainText('Dépense enregistrée');
    await expect
        .poll(async () => (await dialog.boundingBox())!.width)
        .toBeLessThanOrEqual(390);
    await page.screenshot({
        path: testInfo.outputPath('historique-mobile.png'),
        animations: 'disabled',
    });
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
});

test('consultation seule : pas de création ni modification ni validation', async ({
    page,
}) => {
    await mountExpenses(page, { readonly: true });
    await expect(
        page.getByRole('button', { name: 'Nouvelle dépense', exact: true }),
    ).toHaveCount(0);
    await page
        .getByTestId('depense-card')
        .nth(1)
        .getByRole('button', { name: 'Actions' })
        .click();
    for (const label of ['Modifier', 'Valider', 'Rejeter', 'Supprimer'])
        await expect(
            page.getByRole('menuitem', { name: label, exact: true }),
        ).toHaveCount(0);
});

test('filtres : agence, statut, recherche et période restent disponibles', async ({
    page,
}) => {
    await mountExpenses(page);
    await page.getByRole('button', { name: /^Filtres/ }).click();
    const dialog = page.getByRole('dialog', { name: 'Filtres', exact: true });
    await expect(dialog).toContainText('Agence');
    await expect(dialog).toContainText('Statut');
    await expect(dialog).toContainText('Rechercher');
    await expect(dialog).toContainText('Date début');
    await dialog.getByPlaceholder('Rechercher...').fill('salaire');
    const request = page.waitForRequest(
        (r) =>
            r.headers()['x-inertia'] === 'true' &&
            r.url().includes('search=salaire'),
    );
    await dialog
        .getByRole('button', { name: 'Appliquer les filtres', exact: true })
        .click();
    const url = new URL((await request).url());
    expect(url.searchParams.getAll('site_ids[]')).toEqual(['site-matoto']);
    expect(url.searchParams.get('date_debut')).toBe('2026-09-01');
});

test('export mobile : conserve les filtres et le site', async ({ page }) => {
    await mountExpenses(page);
    await page.getByTestId('depenses-export-trigger').click();
    await expect(page.getByTestId('depenses-export-imprimer')).toBeVisible();
    const request = page.waitForRequest((r) =>
        r.url().includes('/backoffice/depenses/export/excel?'),
    );
    await page.getByTestId('depenses-export-excel').click();
    const params = new URL((await request).url()).searchParams;
    expect(params.get('site_ids[]')).toBe('site-matoto');
    expect(params.get('date_debut')).toBe('2026-09-01');
});

test('thème sombre et liste vide sur mobile', async ({ page }, testInfo) => {
    await mountExpenses(page, { dark: true, empty: true });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expect(
        page.getByRole('heading', { name: 'Dépenses', exact: true }),
    ).toBeVisible();
    await expect(page.getByTestId('depense-card')).toHaveCount(0);
    await expect(
        page.getByText('Aucune dépense à afficher', { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Nouvelle dépense', exact: true }),
    ).toHaveCount(1);
    await page.screenshot({
        path: testInfo.outputPath('depenses-vide-sombre.png'),
        animations: 'disabled',
    });
});

test('ordinateur et changement de largeur : une seule création et tableau conservé', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await mountExpenses(page);
    await expect(page.getByTestId('depenses-table')).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Nouvelle dépense', exact: true }),
    ).toHaveCount(1);
    await expect(page.getByTestId('depenses-mobile-summary')).toBeHidden();
    await page
        .getByRole('row')
        .filter({ hasText: 'Avance de septembre' })
        .first()
        .getByRole('button', { name: 'Actions' })
        .click();
    await expect(
        page.getByRole('menuitem', { name: 'Historique', exact: true }),
    ).toBeVisible();
    await page.keyboard.press('Escape');
    await page.setViewportSize({ width: 413, height: 802 });
    await expect(page.getByTestId('depenses-table')).toBeHidden();
    await expect(
        page
            .locator('#depenses-mobile-primary')
            .getByRole('button', { name: 'Nouvelle dépense', exact: true }),
    ).toBeVisible();
    await page.setViewportSize({ width: 1440, height: 900 });
    await expect(
        page
            .getByTestId('list-page-actions')
            .getByRole('button', { name: 'Nouvelle dépense', exact: true }),
    ).toBeVisible();
});
