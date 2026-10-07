import { expect, test, type Page } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

// Vrai bundle, requêtes interceptées : aucune dépense écrite en base.
async function ouvrir(
    page: Page,
    errors: Record<string, string> = {},
    options: {
        longOptions?: boolean;
        noClientTypes?: boolean;
        canChangeSite?: boolean;
    } = {},
) {
    const manifest = JSON.parse(
        await readFile('public/build/manifest.json', 'utf8'),
    );
    const entry = manifest['resources/js/app.ts'];
    const categories = [
        ['vehicule', 'Véhicule'],
        ['proprietaire', 'Propriétaire'],
        ['livreur', 'Livreur'],
        ['employe', 'Salarié'],
        ['interne', 'Dépense interne'],
        ['prestataire', 'Prestataire'],
        ['client', 'Client'],
    ].map(([value, label]) => ({ value, label }));
    const fixture = {
        component: 'Depenses/Create',
        url: '/backoffice/depenses/create',
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
                permissions: { 'depenses.create': true, 'depenses.read': true },
            },
            theme: {
                active: { preset: 'aura', primary: 'blue', surface: 'slate' },
                allowed: {},
                locked: {},
            },
            categories,
            types: categories.map((c) => ({
                id: `type-${c.value}`,
                code: c.value,
                libelle:
                    c.value === 'livreur'
                        ? 'Avance sur salaire'
                        : 'Frais divers',
                categorie: c.value,
                categorie_label: c.label,
                impact_message: '',
                commentaire_obligatoire: false,
                justificatif_obligatoire: false,
            })),
            sites: [{ id: 'site-matoto', nom: 'Matoto' }],
            default_site_id: 'site-matoto',
            can_change_site: options.canChangeSite ?? false,
            vehicules: [],
            employes: [],
            proprietaires: [],
            prestataires: [],
            clients: [],
            livreurs: Array.from({ length: 18 }, (_, i) => ({
                id: `livreur-${i}`,
                nom_complet: i === 17 ? 'Saa Fodé' : `Livreur ${i}`,
                telephone: i === 17 ? '+224 613 85 52 81' : `6220000${i}`,
                vehicule_noms: 'ADAMA',
                vehicule_immatriculations: 'OU114',
                site_nom: 'Matoto',
            })),
        },
    };
    if (options.longOptions) {
        fixture.props.types.push(
            ...Array.from({ length: 15 }, (_, i) => ({
                ...fixture.props.types[0],
                id: `type-long-${i}`,
                categorie: 'client',
                categorie_label: 'Client',
                libelle: `Frais concernant un client avec un intitulé particulièrement long ${i}`,
            })),
        );
        fixture.props.sites.push({
            id: 'site-long',
            nom: 'Agence avec un nom très long pour tester le menu de sélection',
        });
    }
    if (options.noClientTypes)
        fixture.props.types = fixture.props.types.filter(
            (t) => t.categorie !== 'client',
        );
    const data = JSON.stringify(fixture)
        .replaceAll('&', '&amp;')
        .replaceAll('"', '&quot;')
        .replaceAll('<', '&lt;');
    const html = `<!doctype html><html lang="fr"><head><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">${entry.css.map((f: string) => `<link rel="stylesheet" href="/build/${f}">`).join('')}</head><body><div id="app" data-page="${data}"></div><script type="module" src="/build/${entry.file}"></script></body></html>`;
    const publicRoot = path.resolve('public');
    const payloads: Record<string, unknown>[] = [];
    await page.route('**/*', async (route) => {
        const request = route.request();
        const pathname = new URL(request.url()).pathname;
        if (
            pathname === '/backoffice/depenses' &&
            request.method() === 'POST'
        ) {
            payloads.push(request.postDataJSON());
            return route.fulfill({
                headers: { 'X-Inertia': 'true' },
                json: { ...fixture, props: { ...fixture.props, errors } },
            });
        }
        if (pathname === '/backoffice/depenses')
            return route.fulfill({
                headers: { 'X-Inertia': 'true' },
                json: { ...fixture, url: pathname },
            });
        if (pathname === fixture.url)
            return route.fulfill({
                contentType: 'text/html; charset=utf-8',
                body: html,
            });
        const file = path.resolve(publicRoot, `.${pathname}`);
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
    await page.goto('http://ui-preview.test/backoffice/depenses/create');
    await expect(page.locator('#depense-form')).toBeVisible();
    return payloads;
}

async function choisir(page: Page, categorie = 'interne') {
    await page
        .locator('label')
        .filter({ has: page.locator(`input[value="${categorie}"]`) })
        .click();
    await page.locator('#dep-type').click();
    await page.getByRole('option').first().click();
    await expect(page.getByRole('listbox')).toHaveCount(0);
}

for (const width of [360, 390, 413, 440]) {
    test(`mobile ${width} : actions accessibles et saisie sans débordement`, async ({
        page,
    }, info) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));
        await page.setViewportSize({ width, height: 802 });
        await ouvrir(page);
        const footer = page.getByTestId('depense-create-actions');
        await expect(
            page.getByRole('button', { name: 'Brouillon', exact: true }),
        ).toBeDisabled();
        await expect(
            page.getByRole('button', { name: 'Soumettre', exact: true }),
        ).toBeDisabled();
        await expect(
            page.getByRole('button', { name: 'Annuler la saisie' }),
        ).toBeVisible();
        expect(
            await page
                .getByTestId('depense-create-header')
                .evaluate((e) => getComputedStyle(e).position),
        ).toBe('fixed');
        expect(
            (await page
                .locator('label')
                .filter({ has: page.locator('input[value="interne"]') })
                .boundingBox())!.height,
        ).toBeGreaterThanOrEqual(48);
        for (const button of await footer.getByRole('button').all()) {
            if (await button.isVisible()) {
                const box = (await button.boundingBox())!;
                expect(box.height).toBeGreaterThanOrEqual(48);
                expect(box.x + box.width).toBeLessThanOrEqual(width);
            }
        }
        await page.screenshot({ path: info.outputPath('initial.png') });
        await choisir(page);
        await expect(page.locator('#dep-site')).toHaveAttribute(
            'aria-disabled',
            'true',
        );
        await expect(page.locator('#dep-site')).toContainText('Matoto');
        for (const id of ['dep-montant', 'dep-date', 'dep-site']) {
            const field = page.locator(`#${id}`);
            const target = id === 'dep-site' ? field.locator('..') : field;
            expect((await target.boundingBox())!.height).toBeGreaterThanOrEqual(
                44,
            );
            expect(
                await field.evaluate((e) => getComputedStyle(e).fontSize),
            ).toBe('16px');
        }
        await page.locator('#dep-montant').fill('5000');
        await page.locator('#dep-comment').fill('Achat de fournitures');
        await page.locator('#dep-comment').blur();
        await page.evaluate(() =>
            window.scrollTo(0, document.documentElement.scrollHeight),
        );
        const comment = (await page.locator('#dep-comment').boundingBox())!;
        expect(comment.y + comment.height).toBeLessThan(
            (await footer.boundingBox())!.y,
        );
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
        await page.screenshot({ path: info.outputPath('saisie.png') });
        expect(errors).toEqual([]);
    });
}

for (const width of [360, 413, 440]) {
    test(`menus ouverts ${width} : types et sites longs restent dans l'écran`, async ({
        page,
    }, info) => {
        await page.setViewportSize({ width, height: 500 });
        await ouvrir(page, {}, { longOptions: true, canChangeSite: true });
        await page
            .locator('label')
            .filter({ has: page.locator('input[value="client"]') })
            .click();
        await page.locator('#dep-type').click();
        const overlay = page.locator('.p-select-overlay');
        await expect(page.getByRole('listbox')).toBeVisible();
        await overlay.evaluate(async (el) => {
            await Promise.allSettled(el.getAnimations().map((a) => a.finished));
        });
        let box = (await overlay.boundingBox())!;
        expect(box.x).toBeGreaterThanOrEqual(0);
        expect(box.x + box.width).toBeLessThanOrEqual(width);
        expect(box.y).toBeGreaterThanOrEqual(0);
        expect(box.y + box.height).toBeLessThanOrEqual(500);
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
        await page.screenshot({ path: info.outputPath('types-ouverts.png') });
        await page.getByRole('option').last().scrollIntoViewIfNeeded();
        await page.getByRole('option').last().click();
        await expect(page.locator('#dep-type')).toContainText('14');
        await expect(overlay).toHaveCount(0);
        await page.locator('#dep-site').click();
        await expect(page.getByRole('listbox')).toBeVisible();
        await overlay.evaluate(async (el) => {
            await Promise.allSettled(el.getAnimations().map((a) => a.finished));
        });
        box = (await overlay.boundingBox())!;
        expect(box.x).toBeGreaterThanOrEqual(0);
        expect(box.x + box.width).toBeLessThanOrEqual(width);
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
        await page.getByRole('option').last().click();
        await expect(page.locator('#dep-site')).toContainText('Agence');
    });
}

test('type sans options : menu vide contenu dans le mobile', async ({
    page,
}, info) => {
    await page.setViewportSize({ width: 413, height: 802 });
    await ouvrir(page, {}, { noClientTypes: true });
    await page
        .locator('label')
        .filter({ has: page.locator('input[value="client"]') })
        .click();
    await page.locator('#dep-type').click();
    const overlay = page.locator('.p-select-overlay');
    await expect(overlay).toContainText('Aucun type disponible');
    await overlay.evaluate(async (el) => {
        await Promise.allSettled(el.getAnimations().map((a) => a.finished));
    });
    const box = (await overlay.boundingBox())!;
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(413);
    await page.screenshot({ path: info.outputPath('type-vide.png') });
    await page.locator('#dep-type').press('Escape');
    await expect(
        page.getByRole('button', { name: 'Soumettre', exact: true }),
    ).toBeDisabled();
});

test('sélecteur plein écran : recherche téléphone, sélection par ID et effacement', async ({
    page,
}, info) => {
    await page.setViewportSize({ width: 360, height: 500 });
    await ouvrir(page);
    await choisir(page, 'livreur');
    await page.locator('#dep-livreur').click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expect.poll(async () => (await dialog.boundingBox())?.x).toBe(0);
    const box = (await dialog.boundingBox())!;
    expect(box.x).toBe(0);
    expect(box.y).toBe(0);
    expect(box.width).toBe(360);
    expect(box.height).toBe(500);
    await expect(dialog.getByRole('textbox')).toHaveCount(4);
    await dialog.getByLabel('Téléphone').fill('613855281');
    await expect(dialog.getByRole('option')).toHaveCount(1);
    await dialog
        .getByRole('option', { name: /Saa Fodé/ })
        .scrollIntoViewIfNeeded();
    await page.screenshot({ path: info.outputPath('selection.png') });
    await dialog.getByRole('option', { name: /Saa Fodé/ }).click();
    await expect(dialog).toHaveCount(0);
    await expect(page.locator('#dep-livreur')).toContainText('Saa Fodé');
    const clear = page.getByRole('button', { name: 'Effacer la sélection' });
    expect((await clear.boundingBox())!.height).toBeGreaterThanOrEqual(44);
    await clear.click();
    await expect(page.locator('#dep-livreur')).toContainText('Rechercher');
});

test('brouillon : montant numérique, site et identifiants conservés', async ({
    page,
}) => {
    const payloads = await ouvrir(page);
    await choisir(page, 'livreur');
    await page.locator('#dep-livreur').click();
    await page.getByRole('dialog').getByLabel('Nom / Prénom').fill('Saa');
    await page.getByRole('option', { name: /Saa Fodé/ }).click();
    await page.locator('#dep-montant').fill('12 500');
    await page.locator('#dep-date').fill('2026-10-06');
    await page.locator('#dep-comment').fill('Avance demandée');
    await page.getByRole('button', { name: 'Brouillon', exact: true }).click();
    await expect.poll(() => payloads.length).toBe(1);
    expect(payloads[0]).toMatchObject({
        statut: 'brouillon',
        depense_type_id: 'type-livreur',
        beneficiaire_id: 'livreur-17',
        site_id: 'site-matoto',
        montant: 12500,
        date_depense: '2026-10-06',
        commentaire: 'Avance demandée',
    });
});

test('soumission : récapitulatif défilant, retour à la saisie et confirmation', async ({
    page,
}, info) => {
    await page.setViewportSize({ width: 360, height: 500 });
    const payloads = await ouvrir(page);
    await choisir(page);
    await page.locator('#dep-montant').fill('5000');
    await page
        .locator('#dep-comment')
        .fill('Commentaire très long. '.repeat(70));
    await page.getByRole('button', { name: 'Soumettre', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toContainText('5 000 GNF');
    await dialog.evaluate(async (el) => {
        await Promise.all(
            el.getAnimations().map((animation) => animation.finished),
        );
    });
    const confirm = dialog.getByRole('button', { name: "Confirmer l'envoi" });
    const box = (await confirm.boundingBox())!;
    expect(box.y + box.height).toBeLessThanOrEqual(500);
    expect((await dialog.boundingBox())!.height).toBeLessThanOrEqual(450);
    await page.screenshot({ path: info.outputPath('confirmation.png') });
    await dialog.getByRole('button', { name: "Retour à l'édition" }).click();
    await expect(dialog).toHaveCount(0);
    expect(payloads).toHaveLength(0);
    await expect(page.locator('#dep-montant')).toHaveValue(/5\s000/);
    await page.getByRole('button', { name: 'Soumettre', exact: true }).click();
    await page.getByRole('button', { name: "Confirmer l'envoi" }).click();
    await expect.poll(() => payloads.length).toBe(1);
    expect(payloads[0]).toMatchObject({
        statut: 'soumis',
        depense_type_id: 'type-interne',
        site_id: 'site-matoto',
        montant: 5000,
    });
});

test('annulation : saisie protégée et sortie explicite', async ({ page }) => {
    await ouvrir(page);
    await page
        .locator('label')
        .filter({ has: page.locator('input[value="interne"]') })
        .click();
    await page.getByRole('button', { name: 'Annuler la saisie' }).click();
    await expect(page.getByRole('dialog')).toContainText('Quitter la saisie ?');
    await page.getByRole('button', { name: 'Continuer la saisie' }).click();
    await expect(page.locator('input[value="interne"]')).toBeChecked();
    await page.getByRole('button', { name: 'Annuler la saisie' }).click();
    await page
        .getByRole('button', { name: 'Quitter sans enregistrer' })
        .click();
    await expect(page).toHaveURL(/\/backoffice\/depenses$/);
});

test('erreur serveur : message lisible et valeurs conservées', async ({
    page,
}) => {
    const payloads = await ouvrir(page, {
        montant: 'Le montant doit être supérieur à zéro.',
    });
    await choisir(page);
    await page.locator('#dep-montant').fill('0');
    await page.locator('#dep-comment').fill('Saisie à corriger');
    await page.getByRole('button', { name: 'Brouillon', exact: true }).click();
    await expect.poll(() => payloads.length).toBe(1);
    await expect(page.locator('#depense-form')).toContainText(
        'Le montant doit être supérieur à zéro.',
    );
    await expect(page.locator('#dep-comment')).toHaveValue('Saisie à corriger');
    await expect(
        page.getByRole('button', { name: 'Brouillon', exact: true }),
    ).toBeEnabled();
});

for (const width of [768, 1440]) {
    test(`tablette / bureau ${width} : disposition classique et libellés complets`, async ({
        page,
    }, info) => {
        await page.setViewportSize({ width, height: 900 });
        await ouvrir(page);
        await choisir(page);
        await expect(page.getByTestId('depense-create-header')).toBeHidden();
        await expect(
            page.getByRole('button', {
                name: 'Enregistrer comme brouillon',
                exact: true,
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', {
                name: 'Soumettre pour validation',
                exact: true,
            }),
        ).toBeVisible();
        expect(
            await page
                .getByTestId('depense-create-actions')
                .evaluate((e) => getComputedStyle(e).position),
        ).toBe('static');
        const montant = (await page.locator('#dep-montant').boundingBox())!;
        const date = (await page.locator('#dep-date').boundingBox())!;
        expect(montant.y).toBe(date.y);
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
        await page.screenshot({ path: info.outputPath('bureau.png') });
    });
}

test('mobile sombre : thème et actions uniques après redimensionnement', async ({
    page,
}, info) => {
    await page.addInitScript(() => localStorage.setItem('appearance', 'dark'));
    await ouvrir(page);
    await choisir(page);
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.setViewportSize({ width: 768, height: 900 });
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(
        page.getByRole('button', { name: 'Brouillon', exact: true }),
    ).toHaveCount(1);
    await expect(
        page.getByRole('button', { name: 'Soumettre', exact: true }),
    ).toHaveCount(1);
    await page.screenshot({ path: info.outputPath('sombre.png') });
});
