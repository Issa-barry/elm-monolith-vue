import { expect, test, type Page } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

// Vrai bundle avec données fictives : aucune modification en base.
const proprietaire = {
    id: 'owner-1',
    nom: 'Hassane',
    prenom: 'Kawou',
    surnom: null,
    nom_complet: 'Kawou Hassane',
    type: 'particulier',
    raison_sociale: null,
    nom_affichage: 'Kawou Hassane',
    est_entreprise: false,
    email: null,
    telephone: '+224629454591',
    code_phone_pays: '+224',
    ville: 'Conakry',
    pays: 'Guinée',
    code_pays: 'GN',
    adresse: 'Matoto',
    is_active: true,
    vehicules_count: 2,
    has_valid_identity_document: false,
    is_proprietaire_interne: false,
};
const vehicules = [
    {
        id: 1,
        nom_vehicule: 'Abarry',
        immatriculation: 'AI3462',
        photo_url: null,
        type_label: 'Minibus',
        capacites: [{ categorie_nom: 'Bouteille d’eau', capacite_max: 540 }],
        categorie: 'partenaire',
        is_active: true,
        equipe_detail: {
            nom: 'Abarry',
            taux_commission_proprietaire: 20,
            chauffeur: { nom: 'Saa Fodé', telephone: '+224622602693' },
            convoyeurs: [],
        },
    },
    {
        id: 2,
        nom_vehicule: 'ACHOUR',
        immatriculation: 'BG5084',
        photo_url: null,
        type_label: 'Tricycle',
        capacites: [],
        categorie: 'partenaire',
        is_active: false,
        equipe_detail: null,
    },
];
const depenses = [
    {
        id: 'depense-1',
        libelle: 'Carburant',
        montant: 5000,
        date_depense: '06/10/2026',
        statut: 'approuve',
        commentaire: null,
    },
];
const pieces = [
    {
        id: 'piece-1',
        type_piece: 'cni',
        type_piece_label: "Carte nationale d'identité",
        numero_masque: '****1234',
        pays_delivrance: 'GN',
        date_delivrance: null,
        date_expiration: '01/01/2030',
        statut_verification: 'en_attente',
        statut_verification_label: 'En attente',
        statut_affichage: 'en_attente',
        est_active: true,
        motif_rejet: null,
        verifiee_le: null,
        has_recto: true,
        has_verso: false,
        created_at: null,
    },
];

async function ouvrir(
    page: Page,
    options: { dark?: boolean; long?: boolean } = {},
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
            permissions: {
                'proprietaires.update': true,
                'vehicules.read': true,
                'vehicules.update': true,
                'depenses.update': true,
            },
        },
        theme: {
            active: { preset: 'aura', primary: 'blue', surface: 'slate' },
            allowed: {},
            locked: {},
        },
        proprietaire: {
            ...proprietaire,
            ...(options.long
                ? {
                      nom_affichage:
                          'Propriétaire au nom particulièrement long ' +
                          'B'.repeat(40),
                      email: 'adresse.particulierement.longue@exemple-de-domaine.test',
                      adresse: 'Quartier ' + 'A'.repeat(60),
                  }
                : {}),
        },
        vehicules,
        depenses,
        pieces_identite: pieces,
        piece_identite_permissions: {
            can_create: true,
            can_update: true,
            can_delete: true,
            can_download: true,
            can_valider: true,
            can_rejeter: true,
        },
        type_piece_options: [{ value: 'cni', label: "Carte d'identité" }],
        can_create_vehicule: true,
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
        if (url.pathname.startsWith('/backoffice/proprietaires')) {
            if (request.method() !== 'GET')
                mutations.push(request.method() + ' ' + url.pathname);
            const data = {
                component: 'Proprietaires/Show',
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
    await page.goto('http://ui-preview.test/backoffice/proprietaires/owner-1');
    await expect(
        page.getByRole('tab', { name: 'Informations', exact: true }),
    ).toBeAttached();
    return { mutations };
}

async function sansDebordement(page: Page) {
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    expect(
        await page
            .locator('.proprietaire-detail')
            .evaluate((el) => el.scrollWidth <= el.clientWidth),
    ).toBe(true);
}

for (const width of [360, 390, 432, 440]) {
    test(
        'mobile ' + width + ' : fiche propriétaire sans débordement',
        async ({ page }, info) => {
            const errors: string[] = [];
            page.on('pageerror', (error) => errors.push(error.message));
            await page.setViewportSize({ width, height: 900 });
            const { mutations } = await ouvrir(page, { long: true });
            await expect(
                page.getByTestId('proprietaire-mobile-header'),
            ).toContainText('Détail du propriétaire');
            await expect(
                page.getByRole('button', {
                    name: 'Définir comme propriétaire interne',
                }),
            ).toBeVisible();
            await sansDebordement(page);
            await page.screenshot({
                path: info.outputPath('informations.png'),
            });
            for (const tab of ['Vehicules', 'Dépenses', "Pièce d'identité"]) {
                await page.getByRole('tab', { name: new RegExp(tab) }).click();
                await sansDebordement(page);
                await page.screenshot({ path: info.outputPath(tab + '.png') });
            }
            expect(mutations).toEqual([]);
            expect(errors).toEqual([]);
        },
    );
}

test('véhicules mobile : cartes avec libellés, actions conservées', async ({
    page,
}) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await ouvrir(page);
    await page.getByTestId('owner-vehicles-tab').click();
    const table = page.locator('[data-mobile-cards="vehicules"]');
    expect(
        await table
            .locator('tbody tr')
            .first()
            .evaluate((el) => getComputedStyle(el).display),
    ).toBe('grid');
    await expect(table.locator('thead')).toBeHidden();
    await expect(table).toContainText('AI3462');
    await expect(
        table.getByRole('link', { name: 'Abarry', exact: true }),
    ).toHaveAttribute('href', '/backoffice/vehicules/1');
    await expect(
        page.getByRole('button', { name: 'Modifier' }).first(),
    ).toBeVisible();
    await sansDebordement(page);
});

test('clavier : flèches et Fin parcourent les onglets', async ({ page }) => {
    await ouvrir(page);
    const first = page.getByRole('tab', { name: 'Informations', exact: true });
    await first.focus();
    await first.press('ArrowRight');
    await expect(page.getByTestId('owner-vehicles-tab')).toBeFocused();
    await page.keyboard.press('End');
    await expect(page.getByTestId('owner-piece-identite-tab')).toBeFocused();
    await expect(page.getByRole('tabpanel')).toContainText('****1234');
});

test('dialogue pièce d’identité : tient dans l’écran mobile', async ({
    page,
}) => {
    await page.setViewportSize({ width: 360, height: 800 });
    await ouvrir(page);
    await page.getByTestId('owner-piece-identite-tab').click();
    await page.getByRole('button', { name: 'Ajouter une pièce' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await dialog.evaluate(async (el) => {
        await Promise.allSettled(
            el.getAnimations().map((animation) => animation.finished),
        );
    });
    expect((await dialog.boundingBox())!.width).toBeLessThanOrEqual(360);
});

test('mobile sombre : aucun débordement', async ({ page }, info) => {
    await page.setViewportSize({ width: 360, height: 802 });
    await ouvrir(page, { dark: true, long: true });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await sansDebordement(page);
    await page.screenshot({ path: info.outputPath('sombre.png') });
});

test('bureau 1440 : barre latérale verticale et tableau conservés', async ({
    page,
}, info) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await ouvrir(page);
    await expect(page.getByTestId('proprietaire-mobile-header')).toBeHidden();
    await expect(page.getByRole('link', { name: 'Retour' })).toBeVisible();
    const nav = page.getByTestId('proprietaire-tabs');
    expect(await nav.evaluate((el) => getComputedStyle(el).flexDirection)).toBe(
        'column',
    );
    await page.getByTestId('owner-vehicles-tab').click();
    await expect(
        page.locator('[data-mobile-cards="vehicules"] thead'),
    ).toBeVisible();
    await sansDebordement(page);
    await page.screenshot({ path: info.outputPath('bureau.png') });
});
