import { expect, test, type Page } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

// Vrai bundle avec données fictives : aucune modification en base.
const photo =
    'data:image/svg+xml,' +
    encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><rect width="100" height="100" fill="#2563eb"/></svg>',
    );
const vehicule = {
    id: 'vehicule-1',
    nom_vehicule: 'Abarry',
    immatriculation: 'AI3462',
    type_label: 'Minibus',
    type_vehicule_id: 'type-1',
    capacites: [
        {
            categorie_id: 'cat-1',
            categorie_nom: 'Bouteille d’eau',
            capacite_max: 540,
        },
    ],
    site_id: 'site-1',
    site_nom: 'Matoto',
    categorie: 'partenaire',
    categorie_label: 'Partenaire',
    proprietaire_id: 'owner-1',
    proprietaire_nom: 'Saa Fodé',
    proprietaire_nom_affichage: 'Saa Fodé',
    proprietaire_est_entreprise: false,
    proprietaire_telephone: '+224622602693',
    parrain_id: 'parrain-1',
    parrain_nom_complet: 'Moussa SIDIBÉ',
    parrain_telephone: '+224622602693',
    parrain_code_phone_pays: '+224',
    parrain_code_pays: 'GN',
    parrain_pays: 'Guinée',
    parrain_ville: 'Conakry',
    parrain_adresse: null,
    equipe_id: 'equipe-1',
    equipe_membres: [
        {
            livreur_id: 'livreur-1',
            livreur_nom: 'Saa Fodé',
            telephone: '+224622602693',
            taux_commission: 100,
            montant_par_pack: 1000,
            role: 'principal',
            livreur_actif: true,
            livreur_a_un_compte: true,
        },
    ],
    livraison_vente: true,
    livraison_logistique: true,
    photo_url: photo,
    is_active: true,
    derogation_impayes_autorisee: false,
    seuil_derogation_impayes: null,
};
const depenses = [
    {
        id: 'depense-1',
        libelle: 'Carburant',
        montant: 5000,
        date_depense: '06/10/2026',
        statut: 'valide',
        commentaire: null,
    },
];
const detailProps = {
    vehicule,
    distribution_chauffeur_motif: null,
    depenses,
    equipe: {
        id: 'equipe-1',
        is_active: true,
        commission_unitaire_par_pack: 1000,
        montant_par_pack_proprietaire: 500,
        taux_commission_proprietaire: null,
        proprietaire_id: 'owner-1',
        proprietaire_nom: 'Saa Fodé',
        membres: [
            {
                livreur_id: 'livreur-1',
                nom_complet: 'Saa Fodé',
                telephone: '+224622602693',
                role: 'principal',
                montant_par_pack: 1000,
                taux_commission: 100,
                ordre: 1,
            },
        ],
        partages_categorie: [
            {
                categorie_id: 'cat-1',
                parts: [{ livreur_id: 'livreur-1', montant_unitaire: 1000 }],
            },
        ],
    },
    situation_ventes: {
        kpis: {
            ca_vendu: 20000,
            encaisse: 15000,
            reste_du: 5000,
            nb_ventes: 2,
        },
        produits: [],
        paiements: { total_montant: 20000, total_ventes: 2, repartition: [] },
    },
    situation_periode: {
        cle: 'tout',
        date_debut: null,
        date_fin: null,
        options: [{ value: 'tout', label: 'Tout' }],
    },
    proprietaires: [],
    default_proprietaire_id: 'owner-1',
    seuil_global_impayes: 500000,
    baremes_commission_categories: [
        {
            categorie_id: 'cat-1',
            categorie_nom: 'Bouteille d’eau',
            montant_proprietaire: 500,
            montant_livraison: 1000,
        },
    ],
    statuts_partage_commission: {
        'cat-1': {
            vente: 'fait',
            distribution_client: 'non_requis',
            logistique_transfert: 'a_faire',
        },
    },
    processus_actif: 'vente',
    processus_options: [
        { value: 'vente', label: 'Vente' },
        { value: 'distribution_client', label: 'Distribution' },
        { value: 'logistique_transfert', label: 'Transfert' },
    ],
};
const listeProps = {
    vehicules: [
        vehicule,
        {
            ...vehicule,
            id: 'vehicule-2',
            nom_vehicule: 'ACHOUR',
            immatriculation: 'BG5084',
            photo_url: null,
            is_active: false,
        },
    ],
    filters: {},
    types_options: [],
    agences_proprietaires_options: [],
    vehicule_stats: {
        total: 2,
        actifs: 1,
        inactifs: 1,
        sansEquipe: 0,
        parType: [{ label: 'Minibus', count: 2 }],
    },
};

async function ouvrir(
    page: Page,
    options: {
        list?: boolean;
        readonly?: boolean;
        dark?: boolean;
        long?: boolean;
        tab?: string;
    } = {},
) {
    const manifest = JSON.parse(
        await readFile('public/build/manifest.json', 'utf8'),
    );
    const entry = manifest['resources/js/app.ts'];
    const assets = entry.css
        .map(
            (file: string) =>
                '<link rel="stylesheet" href="/build/' + file + '">',
        )
        .join('');
    const sharedProps = {
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
                'vehicules.read': true,
                'vehicules.update': !options.readonly,
                'vehicules.delete': !options.readonly,
                'depenses.update': !options.readonly,
            },
        },
        theme: {
            active: { preset: 'aura', primary: 'blue', surface: 'slate' },
            allowed: {},
            locked: {},
        },
    };
    const detail = {
        ...detailProps,
        vehicule: {
            ...vehicule,
            ...(options.long
                ? {
                      nom_vehicule:
                          'Véhicule avec un nom particulièrement long pour la livraison',
                      site_nom:
                          'Agence de Matoto avec une référence ' +
                          'A'.repeat(50),
                      proprietaire_nom_affichage:
                          'Propriétaire de la société ' + 'B'.repeat(50),
                  }
                : {}),
        },
    };
    function inertiaPage(url: URL) {
        const isList = url.pathname === '/backoffice/vehicules';
        return {
            component: isList ? 'Vehicules/Index' : 'Vehicules/Show',
            url: url.pathname + url.search,
            version: null,
            props: { ...sharedProps, ...(isList ? listeProps : detail) },
        };
    }
    await page.addInitScript(
        (appearance) => localStorage.setItem('appearance', appearance),
        options.dark ? 'dark' : 'light',
    );
    const publicRoot = path.resolve('public');
    const mutations: string[] = [];
    const visits: string[] = [];
    await page.route('**/*', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        if (url.pathname.startsWith('/backoffice/saved-filters'))
            return route.fulfill({ json: { data: [] } });
        if (url.pathname.startsWith('/backoffice/vehicules')) {
            visits.push(url.pathname + url.search);
            if (request.method() !== 'GET')
                mutations.push(request.method() + ' ' + url.pathname);
            const data = inertiaPage(url);
            if (request.headers()['x-inertia'])
                return route.fulfill({
                    headers: { 'X-Inertia': 'true' },
                    json: data,
                });
            const escaped = JSON.stringify(data)
                .replaceAll('&', '&amp;')
                .replaceAll('"', '&quot;')
                .replaceAll('<', '&lt;');
            return route.fulfill({
                contentType: 'text/html; charset=utf-8',
                body:
                    '<!doctype html><html lang="fr"><head><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">' +
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
    await page.goto(
        'http://ui-preview.test/backoffice/vehicules' +
            (options.list
                ? ''
                : '/vehicule-1' + (options.tab ? '?tab=' + options.tab : '')),
    );
    await expect(
        options.list
            ? page.getByTestId('vehicule-mobile-row').first()
            : page.getByRole('tab', { name: 'Informations', exact: true }),
    ).toBeAttached();
    return { mutations, visits };
}

async function sansDebordement(page: Page) {
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    const detail = page.locator('.vehicule-detail');
    if (await detail.count())
        expect(
            await detail.evaluate((el) => el.scrollWidth <= el.clientWidth),
        ).toBe(true);
}

for (const width of [360, 390, 432, 440]) {
    test(
        'mobile ' + width + ' : ligne entière cliquable et fiche compacte',
        async ({ page }, info) => {
            const errors: string[] = [];
            page.on('pageerror', (error) => errors.push(error.message));
            await page.setViewportSize({ width, height: 802 });
            const { mutations } = await ouvrir(page, { list: true });
            const row = page.getByTestId('vehicule-mobile-row').first();
            await expect(row).toHaveAttribute(
                'href',
                '/backoffice/vehicules/vehicule-1',
            );
            await expect(row.getByRole('button')).toHaveCount(0);
            await sansDebordement(page);
            await page.screenshot({ path: info.outputPath('liste.png') });
            // La photo est une partie de la ligne : elle ouvre aussi la fiche.
            await row.getByRole('img').click();
            await expect(page).toHaveURL(/\/vehicule-1$/);
            await expect(
                page.getByTestId('vehicule-mobile-header'),
            ).toContainText('Détail du véhicule');
            await expect(
                page.getByRole('region', { name: 'Résumé du véhicule' }),
            ).toHaveCount(0);
            await expect(page.getByTestId('vehicule-identity')).toContainText(
                'AI3462',
            );
            await expect(
                page.getByRole('heading', {
                    level: 1,
                    name: 'Abarry',
                    exact: true,
                }),
            ).toBeVisible();
            const edit = page
                .getByTestId('vehicule-mobile-actions')
                .getByRole('link', { name: 'Modifier le véhicule' });
            await expect(edit).toHaveAttribute(
                'href',
                '/backoffice/vehicules/vehicule-1/edit',
            );
            expect((await edit.boundingBox())!.height).toBeGreaterThanOrEqual(
                44,
            );
            await expect(
                page.getByRole('link', { name: 'Modifier', exact: true }),
            ).toBeHidden();
            await sansDebordement(page);
            await page.screenshot({
                path: info.outputPath('informations.png'),
            });
            const nav = page.getByTestId('vehicule-tabs');
            expect(
                await nav.evaluate((el) => el.scrollWidth > el.clientWidth),
            ).toBe(true);
            for (const tab of ['Équipe', 'Parrain', 'Situation', 'Dépenses']) {
                await page.getByRole('tab', { name: new RegExp(tab) }).click();
                await expect(page).toHaveURL(
                    new RegExp(
                        'tab=' +
                            {
                                Équipe: 'equipe',
                                Parrain: 'parrain',
                                Situation: 'situation',
                                Dépenses: 'depenses',
                            }[tab],
                    ),
                );
                await sansDebordement(page);
                await page.screenshot({ path: info.outputPath(tab + '.png') });
            }
            await expect(page.getByRole('tabpanel')).toContainText('5 000 GNF');
            await expect(page.getByRole('tabpanel')).toContainText('Validé');
            expect(mutations).toEqual([]);
            expect(errors).toEqual([]);
        },
    );
}

test('équipe : parts par catégorie, actions et processus serveur conservés', async ({
    page,
}, info) => {
    const { visits, mutations } = await ouvrir(page, { tab: 'equipe' });
    const panel = page.getByRole('tabpanel');
    const table = panel.locator('[data-mobile-cards="membres"]');
    expect(
        await table
            .locator('tbody tr')
            .first()
            .evaluate((el) => getComputedStyle(el).display),
    ).toBe('grid');
    await expect(table).toContainText('Saa Fodé');
    await expect(table).toContainText('1 000 GNF');
    await expect(panel.locator('[data-mobile-cards="baremes"]')).toContainText(
        '500 GNF',
    );
    await page.getByRole('button', { name: 'Transfert', exact: true }).click();
    await expect
        .poll(() =>
            visits.some(
                (url) =>
                    url.includes('tab=equipe') &&
                    url.includes('processus=logistique_transfert'),
            ),
        )
        .toBe(true);
    await sansDebordement(page);
    await page.screenshot({ path: info.outputPath('equipe.png') });
    expect(mutations).toEqual([]);
});

test('suppression : confirmation obligatoire et route existante', async ({
    page,
}, info) => {
    const { mutations } = await ouvrir(page);
    const remove = page.getByRole('button', {
        name: 'Supprimer le véhicule',
        exact: true,
    });
    await remove.click();
    const dialog = page.getByRole('alertdialog');
    await expect(dialog).toContainText('AI3462');
    await dialog.evaluate(async (el) => {
        await Promise.allSettled(
            el.getAnimations().map((animation) => animation.finished),
        );
    });
    expect((await dialog.boundingBox())!.width).toBeLessThanOrEqual(390);
    await page.screenshot({ path: info.outputPath('suppression.png') });
    expect(mutations).toEqual([]);
    await dialog.getByRole('button', { name: 'Annuler', exact: true }).click();
    await expect(dialog).toHaveCount(0);
    await remove.click();
    await page
        .getByRole('alertdialog')
        .getByRole('button', { name: 'Supprimer', exact: true })
        .click();
    await expect
        .poll(() => mutations)
        .toEqual(['DELETE /backoffice/vehicules/vehicule-1']);
});

test('liste bureau : menu de ligne conservé', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await ouvrir(page, { list: true });
    const row = page.locator('.p-datatable tbody tr').first();
    await row.getByRole('button').click();
    await expect(
        page.getByRole('menuitem', { name: 'Voir le détail' }),
    ).toBeVisible();
    await expect(
        page.getByRole('menuitem', { name: 'Modifier' }),
    ).toBeVisible();
    await expect(
        page.getByRole('menuitem', { name: 'Supprimer' }),
    ).toBeVisible();
});

test('lecture seule, photo et clavier : droits et navigation accessibles', async ({
    page,
}) => {
    await ouvrir(page, { readonly: true });
    await expect(page.getByTestId('vehicule-mobile-actions')).toHaveCount(0);
    await expect(
        page.getByRole('button', { name: 'Supprimer le véhicule' }),
    ).toHaveCount(0);
    await page
        .getByTestId('vehicule-identity')
        .getByRole('img', { name: 'Abarry', exact: true })
        .click();
    await expect(page.locator('img[src^="data:image/svg+xml"]')).toHaveCount(2);
    await page.keyboard.press('Escape');
    await expect(page.locator('img[src^="data:image/svg+xml"]')).toHaveCount(1);
    const first = page.getByRole('tab', { name: 'Informations', exact: true });
    await first.focus();
    await first.press('End');
    await expect(page.getByRole('tab', { name: /Dépenses/ })).toBeFocused();
    await expect(page).toHaveURL(/tab=depenses/);
    await sansDebordement(page);
});

test('mobile sombre et textes longs : aucun débordement', async ({
    page,
}, info) => {
    await page.setViewportSize({ width: 360, height: 802 });
    await ouvrir(page, { dark: true, long: true });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await sansDebordement(page);
    await page.screenshot({ path: info.outputPath('sombre.png') });
});

for (const width of [768, 1440]) {
    test(
        'tablette / bureau ' + width + ' : tableaux et actions conservés',
        async ({ page }, info) => {
            await page.setViewportSize({ width, height: 900 });
            await ouvrir(page, { tab: 'equipe' });
            await expect(
                page.getByTestId('vehicule-mobile-header'),
            ).toBeHidden();
            await expect(
                page.getByTestId('vehicule-mobile-actions'),
            ).toBeHidden();
            expect(
                await page
                    .locator('[data-mobile-cards="membres"] tbody tr')
                    .first()
                    .evaluate((el) => getComputedStyle(el).display),
            ).toBe('table-row');
            await sansDebordement(page);
            await page.screenshot({ path: info.outputPath('bureau.png') });
        },
    );
}
