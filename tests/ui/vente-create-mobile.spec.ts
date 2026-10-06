import { expect, test, type Page } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

// Vrai bundle et vrai thème, données fictives : aucun accès à la base ou création de vente.
async function ouvrirFormulaire(page: Page, precommande = false) {
    const manifest = JSON.parse(
        await readFile('public/build/manifest.json', 'utf8'),
    );
    const entry = manifest['resources/js/app.ts'];
    const url = precommande
        ? '/backoffice/precommandes/create'
        : '/backoffice/ventes/create';
    const initialPage = {
        component: 'Ventes/Create',
        url,
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
                    'ventes.prix.update': true,
                },
            },
            theme: {
                active: { preset: 'aura', primary: 'blue', surface: 'slate' },
                allowed: {},
                locked: {},
            },
            produits: [
                {
                    id: 1,
                    nom: 'Pack Bouteille de 1500ml',
                    categorie_id: 1,
                    prix_vente: 20000,
                    prix_usine: 18000,
                    is_fabricable: true,
                    prix_externe: 20000,
                    prix_revendeur: 20000,
                    prix_distributeur: 19000,
                },
            ],
            vehicules: [],
            vehicules_distribution: [],
            clients: [
                {
                    id: 1,
                    nom_complet: 'Thierno Exemple',
                    telephone: '622123456',
                    type: 'revendeur',
                    type_label: 'Revendeur',
                    vehicules: [],
                },
            ],
            user_site: { id: 1, nom: 'Matoto', label: 'Agence de Matoto' },
            can_modifier_qte: true,
            autoriser_saisie_dessous_qte_max: true,
            ...(precommande
                ? {
                      precommande: {
                          acompte_obligatoire: false,
                          acompte_min_pct: 0,
                          moyens_encaissement: [],
                          peut_encaisser_especes: false,
                      },
                  }
                : {}),
        },
    };
    const data = JSON.stringify(initialPage)
        .replaceAll('&', '&amp;')
        .replaceAll('"', '&quot;')
        .replaceAll('<', '&lt;');
    const html = `<!doctype html><html lang="fr"><head><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">${entry.css.map((file: string) => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><div id="app" data-page="${data}"></div><script type="module" src="/build/${entry.file}"></script></body></html>`;
    const publicRoot = path.resolve('public');
    await page.route('**/*', async (route) => {
        const pathname = new URL(route.request().url()).pathname;
        if (pathname === url)
            return route.fulfill({
                contentType: 'text/html; charset=utf-8',
                body: html,
            });
        if (pathname === '/backoffice/ventes/check-solvabilite')
            return route.fulfill({
                json: {
                    cible: 'client',
                    has_debt: false,
                    status: 'aucun',
                    blocked: false,
                    controle_actif: true,
                    unpaid_invoices_count: 0,
                    total_remaining: 0,
                    total_encaisse: 0,
                    factures: [],
                    seuil_impayes: 20000000,
                    seuil_origine: 'standard',
                    exposition: 0,
                    montant_disponible: 20000000,
                },
            });
        if (
            pathname === '/backoffice/ventes' ||
            pathname === '/backoffice/precommandes'
        ) {
            return route.fulfill({
                headers: { 'X-Inertia': 'true' },
                json: { ...initialPage, url: pathname },
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
    await expect(page.locator('#vente-form')).toBeVisible();
}

for (const width of [360, 390, 440]) {
    test(`mobile ${width}px : action unique, lisibilité et fin de formulaire accessible`, async ({
        page,
    }, testInfo) => {
        const erreurs: string[] = [];
        page.on('pageerror', (error) => erreurs.push(error.message));
        await page.setViewportSize({ width, height: 844 });
        await ouvrirFormulaire(page);
        const creer = page.getByRole('button', {
            name: 'Créer la commande',
            exact: true,
        });
        await expect(creer).toHaveCount(1);
        await expect(
            page.getByRole('button', { name: 'Annuler', exact: true }),
        ).toHaveCount(1);
        await expect(
            page.getByRole('heading', {
                name: 'Nouvelle commande',
                exact: true,
            }),
        ).toBeVisible();
        expect((await creer.boundingBox())!.height).toBeGreaterThanOrEqual(48);
        const qte = page.getByRole('spinbutton', {
            name: 'Quantité',
            exact: true,
        });
        expect(
            await qte.evaluate((input) =>
                parseFloat(getComputedStyle(input).fontSize),
            ),
        ).toBe(16);
        expect((await qte.boundingBox())!.height).toBeGreaterThanOrEqual(44);
        await qte.fill('540');
        await qte.blur();
        await expect(page.locator('#vente-form')).toContainText(
            /10\s800\s000\sGNF/,
        );
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
        await page
            .getByRole('button', { name: 'Ajouter une ligne', exact: true })
            .scrollIntoViewIfNeeded();
        await page.evaluate(() =>
            window.scrollTo(0, document.documentElement.scrollHeight),
        );
        const ajouter = await page
            .getByRole('button', { name: 'Ajouter une ligne', exact: true })
            .boundingBox();
        const footer = await creer.boundingBox();
        expect(ajouter!.y + ajouter!.height).toBeLessThan(footer!.y);
        await page.screenshot({
            path: testInfo.outputPath('formulaire-mobile.png'),
            fullPage: true,
        });
        await page
            .getByRole('button', { name: 'Ajouter une ligne', exact: true })
            .click();
        await expect(
            page.getByRole('spinbutton', { name: 'Quantité', exact: true }),
        ).toHaveCount(2);
        await page
            .getByRole('button', { name: 'Supprimer la ligne 2', exact: true })
            .click();
        await expect(qte).toHaveCount(1);
        expect(erreurs).toEqual([]);
    });
}

test('annuler une saisie modifiée : continuer conserve les valeurs, quitter rejoint les ventes', async ({
    page,
}) => {
    const mutations: string[] = [];
    page.on('request', (request) => {
        if (request.method() !== 'GET') mutations.push(request.url());
    });
    await ouvrirFormulaire(page);
    const qte = page.getByRole('spinbutton', { name: 'Quantité', exact: true });
    await qte.fill('540');
    await qte.blur();
    await page.getByRole('button', { name: 'Annuler', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'Annuler la saisie ?' });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Continuer la saisie' }).click();
    await expect(dialog).not.toBeVisible();
    await expect(qte).toHaveValue('540');
    await page.getByRole('button', { name: 'Annuler', exact: true }).click();
    await dialog
        .getByRole('button', { name: 'Quitter sans enregistrer' })
        .click();
    await expect(page).toHaveURL('http://ui-preview.test/backoffice/ventes');
    expect(mutations).toEqual([]);
});

test('la présélection du produit ne demande pas de confirmation pour annuler', async ({
    page,
}) => {
    await ouvrirFormulaire(page);
    await page.getByRole('button', { name: 'Annuler', exact: true }).click();
    await expect(page).toHaveURL('http://ui-preview.test/backoffice/ventes');
    await expect(
        page.getByRole('dialog', { name: 'Annuler la saisie ?' }),
    ).not.toBeVisible();
});

test('le bouton fixe ouvre la confirmation de création avec les montants saisis', async ({
    page,
}) => {
    const mutations: string[] = [];
    page.on('request', (request) => {
        if (request.method() !== 'GET') mutations.push(request.url());
    });
    await ouvrirFormulaire(page);
    await page.getByPlaceholder('Nom, prénom, téléphone…').fill('Thierno');
    await page
        .getByRole('option')
        .filter({ hasText: 'Thierno Exemple' })
        .click();
    const qte = page.getByRole('spinbutton', { name: 'Quantité', exact: true });
    await qte.fill('540');
    await qte.blur();
    await page
        .getByRole('button', { name: 'Créer la commande', exact: true })
        .click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await expect(page.getByRole('dialog')).toContainText(/10\s800\s000\sGNF/);
    expect(mutations).toEqual([]);
});

test('précommande : action unique et confirmation avant de quitter vers les précommandes', async ({
    page,
}) => {
    await ouvrirFormulaire(page, true);
    await expect(
        page.getByRole('button', {
            name: 'Enregistrer la précommande',
            exact: true,
        }),
    ).toHaveCount(1);
    await page
        .getByRole('button', { name: 'Retrait sur site', exact: true })
        .click();
    await page.getByRole('button', { name: 'Annuler', exact: true }).click();
    await page
        .getByRole('button', { name: 'Quitter sans enregistrer' })
        .click();
    await expect(page).toHaveURL(
        'http://ui-preview.test/backoffice/precommandes',
    );
});

test('desktop : une seule paire Annuler / Créer dans le formulaire', async ({
    page,
}, testInfo) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await ouvrirFormulaire(page);
    await expect(
        page.getByRole('button', { name: 'Créer la commande', exact: true }),
    ).toHaveCount(1);
    await expect(
        page.getByRole('button', { name: 'Annuler', exact: true }),
    ).toHaveCount(1);
    await expect(
        page
            .locator('#vente-form')
            .getByRole('button', { name: 'Créer la commande', exact: true }),
    ).toBeVisible();
    await expect(page.locator('.vente-create-mobile-footer')).not.toBeVisible();
    await page.screenshot({
        path: testInfo.outputPath('formulaire-desktop.png'),
        fullPage: true,
    });
});

test('mobile sombre et hauteur réduite : le total reste au-dessus de la barre fixe', async ({
    page,
}, testInfo) => {
    await page.setViewportSize({ width: 390, height: 540 });
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.addInitScript(() => localStorage.setItem('appearance', 'dark'));
    await ouvrirFormulaire(page);
    await page.evaluate(() =>
        window.scrollTo(0, document.documentElement.scrollHeight),
    );
    const footer = page.locator('.vente-create-mobile-footer');
    const ajouter = page.getByRole('button', {
        name: 'Ajouter une ligne',
        exact: true,
    });
    expect(
        (await ajouter.boundingBox())!.y +
            (await ajouter.boundingBox())!.height,
    ).toBeLessThan((await footer.boundingBox())!.y);
    await expect(page.locator('html')).toHaveClass(/dark/);
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    await page.screenshot({
        path: testInfo.outputPath('formulaire-sombre.png'),
        fullPage: true,
    });
});
