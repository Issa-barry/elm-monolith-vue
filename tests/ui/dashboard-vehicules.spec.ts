import { expect, test, type Page } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

// Vrai bundle avec données fictives (Stat-Véhicules) : aucune requête vers la base.
const operations = [
    ['vente', 'Vente standard', 'Vente', 120, 112, 8, 2450, 105, 1830],
    [
        'distribution',
        'Distribution client',
        'Logistique',
        40,
        31,
        9,
        310,
        28,
        240,
    ],
    ['grossiste', 'Livraison grossiste', 'Grossiste', 0, 0, 0, 0, 0, 0],
    ['transfert', 'Transfert', 'Logistique', 40, 40, 0, 180, 22, 160],
].map(
    ([
        code,
        libelle,
        usage,
        vehicules,
        possibles,
        bloques,
        rotations,
        utilises,
        jours,
    ]) => ({
        code,
        libelle,
        usage,
        vehicules,
        possibles,
        bloques,
        rotations,
        vehicules_utilises: utilises,
        jours_vehicule: jours,
    }),
);

const vehicules = Array.from({ length: 14 }, (_, i) => ({
    id: `v${i + 1}`,
    nom:
        i === 0
            ? 'Tricycle au nom particulièrement long ' + 'B'.repeat(30)
            : `Tricycle ${i + 1}`,
    immatriculation: `AB-${100 + i}`,
    site: i % 2 ? 'Matoto' : 'Kaloum',
    type: 'Tricycle',
    usages: 'Vente',
    statut: i % 5 === 0 ? 'a_configurer' : 'operationnel',
    statut_label: i % 5 === 0 ? 'À configurer' : 'Opérationnel',
    supprime: false,
    rotations: 40 - i,
    jours: 20 - i,
    avec_retour: i % 3,
    depenses: 125000 * (i + 1),
}));

async function ouvrir(
    page: Page,
    options: { dark?: boolean; sansSources?: boolean } = {},
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
    const sans = options.sansSources === true;
    const props = {
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
            permissions: { 'vehicules.read': true },
        },
        theme: {
            active: { preset: 'aura', primary: 'blue', surface: 'slate' },
            allowed: {},
            locked: {},
        },
        periode: {
            cle: 'ce_mois',
            date_debut: '2026-11-01',
            date_fin: '2026-11-30',
            options: [{ value: 'ce_mois', label: 'Ce mois' }],
            libelle: 'Ce mois (du 01/11/2026 au 30/11/2026)',
        },
        agences: [
            { value: 'k', label: 'Kaloum' },
            { value: 'm', label: 'Matoto' },
        ],
        agence: null,
        perimetre_limite: false,
        blocs: { ventes: !sans, transferts: !sans, depenses: !sans },
        parc: {
            total: 172,
            actifs: 160,
            inactifs: 12,
            operationnels: 143,
            a_configurer: 17,
            en_mission: sans
                ? null
                : { vehicules: 38, ventes: 33, transferts: 5 },
        },
        operations: sans
            ? operations.map((o) => ({
                  ...o,
                  rotations: null,
                  vehicules_utilises: null,
                  jours_vehicule: null,
              }))
            : operations,
        activite: sans
            ? null
            : {
                  rotations: 2940,
                  vehicules_utilises: 151,
                  actifs: 160,
                  actifs_utilises: 148,
                  taux_utilisation: 92.5,
                  jours_vehicule: 2210,
                  avec_retour: 96,
                  taux_retour: 3.265,
                  transferts_avec_ecart: 4,
              },
        evolution: sans
            ? null
            : {
                  granularite: 'jour',
                  tranches: Array.from({ length: 27 }, (_, i) => ({
                      cle: `2026-11-${String(i + 1).padStart(2, '0')}`,
                      label: `${String(i + 1).padStart(2, '0')}/11`,
                  })),
                  series: operations.map((o, k) => ({
                      code: o.code,
                      label: o.libelle,
                      valeurs: Array.from({ length: 27 }, (_, i) =>
                          k === 2 ? 0 : 20 + ((i * (k + 3)) % 17),
                      ),
                  })),
              },
        categories: [
            {
                categorie_id: 'b',
                categorie: 'Bouteille d’eau',
                capacite_parc: 12570,
                vehicules_plafonnes: 150,
                charge: sans ? null : 184500,
                chargements: sans ? null : 2600,
                remplissage: sans ? null : 87.42,
                retourne: sans ? null : 1200,
                taux_retour: sans ? null : 0.65,
                ecarts_manquants: sans ? null : 36,
                ecarts_surplus: sans ? null : 4,
            },
            {
                categorie_id: 's',
                categorie: 'Sachet d’eau',
                capacite_parc: 4285,
                vehicules_plafonnes: 41,
                charge: sans ? null : 0,
                chargements: sans ? null : 0,
                remplissage: null,
                retourne: sans ? null : 0,
                taux_retour: null,
                ecarts_manquants: sans ? null : 0,
                ecarts_surplus: sans ? null : 0,
            },
        ],
        depenses: sans
            ? null
            : {
                  montant: 18450000,
                  nombre: 214,
                  vehicules: 88,
                  rotations: 2940,
                  cout_par_rotation: 6275.51,
              },
        vehicules: vehicules.map((v) =>
            sans
                ? {
                      ...v,
                      rotations: null,
                      jours: null,
                      avec_retour: null,
                      depenses: null,
                  }
                : v,
        ),
        a_configurer: [
            {
                id: 'v1',
                nom: 'Camion sans équipe',
                immatriculation: 'RC-4521',
                site: 'Matoto',
                usages: 'Logistique',
                motifs: [
                    'Distribution impossible : Ce véhicule n’a aucune équipe de livraison — une distribution nécessite un livreur.',
                ],
            },
            {
                id: 'v2',
                nom: 'Tricycle 7',
                immatriculation: null,
                site: 'Kaloum',
                usages: 'Vente',
                motifs: [
                    'Capacité maximale à renseigner pour Sachet d’eau : la vente de ces produits est refusée.',
                ],
            },
        ],
    };
    await page.addInitScript(
        (appearance) => localStorage.setItem('appearance', appearance),
        options.dark ? 'dark' : 'light',
    );
    const publicRoot = path.resolve('public');
    const mutations: string[] = [];
    await page.route('**/*', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        if (url.pathname.startsWith('/backoffice/tableau-de-bord/vehicules')) {
            if (request.method() !== 'GET')
                mutations.push(request.method() + ' ' + url.pathname);
            const data = {
                component: 'DashboardVehicules',
                url: url.pathname + url.search,
                version: null,
                props,
            };
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
        'http://ui-preview.test/backoffice/tableau-de-bord/vehicules',
    );
    await expect(page.getByTestId('kpi-actifs')).toBeVisible();
    return { mutations };
}

async function sansDebordement(page: Page) {
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
}

for (const width of [360, 390, 768]) {
    test(
        'largeur ' + width + ' : aucun débordement de la page',
        async ({ page }, info) => {
            const errors: string[] = [];
            page.on('pageerror', (error) => errors.push(error.message));
            await page.setViewportSize({ width, height: 900 });
            const { mutations } = await ouvrir(page);
            await sansDebordement(page);
            await page.screenshot({
                path: info.outputPath('page.png'),
                fullPage: true,
            });
            expect(mutations).toEqual([]);
            expect(errors).toEqual([]);
        },
    );
}

test('bureau 1440 : cartes, tableaux et graphique', async ({ page }, info) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.setViewportSize({ width: 1440, height: 900 });
    await ouvrir(page);

    await expect(page.getByTestId('kpi-utilisation')).toContainText('92,5 %');
    await expect(page.getByTestId('kpi-rotations')).toContainText(
        '2 760 ventes · 180 transferts',
    );
    await expect(page.getByTestId('vehicules-operations')).toContainText(
        'Livraison grossiste',
    );
    await expect(page.getByTestId('vehicules-note-grossiste')).toContainText(
        'Aucun véhicule actif du parc n’a l’usage Grossiste',
    );
    await expect(page.locator('canvas')).toHaveCount(1);
    await expect(
        page.getByTestId('vehicules-categories').locator('tbody tr'),
    ).toHaveCount(2);
    await expect(page.getByTestId('vehicule-fiche').first()).toHaveAttribute(
        'href',
        '/backoffice/vehicules/v1',
    );
    await expect(page.getByTestId('vehicules-a-configurer')).toContainText(
        'Distribution impossible',
    );
    await sansDebordement(page);
    await page.screenshot({
        path: info.outputPath('bureau.png'),
        fullPage: true,
    });
    expect(errors).toEqual([]);
});

test('sombre : lisible et sans débordement', async ({ page }, info) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await ouvrir(page, { dark: true });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await sansDebordement(page);
    await page.screenshot({
        path: info.outputPath('sombre.png'),
        fullPage: true,
    });
});

test('sans accès aux ventes, aux transferts ni aux dépenses : parc seul, et la page le dit', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await ouvrir(page, { sansSources: true });

    await expect(page.getByTestId('kpi-rotations')).toHaveCount(0);
    await expect(page.getByTestId('kpi-depenses-validees')).toHaveCount(0);
    await expect(page.getByTestId('kpi-en-mission')).toHaveCount(0);
    await expect(page.locator('canvas')).toHaveCount(0);
    await expect(
        page.getByTestId('dashboard-vehicules-sources-absentes'),
    ).toHaveText('Non affiché faute d’accès : ventes, transferts, dépenses.');
    await expect(
        page.getByTestId('vehicules-classement').locator('th'),
    ).toHaveText(['Véhicule', 'Agence actuelle', 'Type', 'État']);
});
