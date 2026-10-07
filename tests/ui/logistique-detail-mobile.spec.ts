import { expect, test, type Locator, type Page } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

// Vrai bundle, données fictives et POST interceptés : aucun transfert modifié en base.
const photo = `data:image/svg+xml,${encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80"><rect width="80" height="80" fill="#2563eb"/></svg>')}`;
const transfert = {
    id: 17,
    reference: 'TRF-070926-002',
    site_source_nom: 'Kouria',
    site_source_id: 1,
    site_destination_nom: 'Matoto',
    site_destination_id: 2,
    vehicule_id: 3,
    vehicule_nom: 'Alphadjo',
    immatriculation: 'BC3448',
    equipe_livraison_id: 4,
    equipe_nom: 'Alphadjo',
    statut: 'reception',
    statut_label: 'Réceptionné',
    statut_dot_class: 'bg-emerald-500',
    date_depart_prevue: '07/09/2026',
    date_depart_reelle: '07/09/2026',
    date_arrivee_prevue: '07/09/2026',
    date_arrivee_reelle: '07/09/2026',
    notes: 'Contacter le responsable à l’arrivée.',
    createur: 'Moussa SIDIBÉ',
    validation_reception: 'accord',
    validated_by_nom: 'Moussa SIDIBÉ',
    validated_at: '07/09/2026',
    validation_motif: null,
    commission: null,
    commission_generique_genere: true,
    commission_generique_montant_total: 80000,
    commission_statut: 'impaye',
    commission_statut_label: 'Impayé',
    commission_generique_livreurs: [
        {
            id: 'livreur-1',
            nom: 'Saa Fodé',
            montant_unitaire: 1000,
            montant: 30000,
            statut_label: 'Impayé',
            statut_dot_class: 'bg-orange-500',
        },
    ],
    is_brouillon: false,
    is_cloture: false,
    is_terminal: false,
    is_annule: false,
    is_editable: false,
    created_at: '07/09/2026',
    lignes: Array.from({ length: 4 }, (_, i) => ({
        id: i + 1,
        produit_id: i + 1,
        produit_nom: i
            ? `Pack de bouteilles ${i}`
            : 'Pack Bouteille de 1500ml avec un nom particulièrement long',
        produit_code: `10000${i}`,
        produit_image_url: i ? null : photo,
        quantite_demandee: 1000,
        quantite_chargee: 1000,
        quantite_recue: 900,
        ecart: -100,
        ecart_type: 'manquant',
        ecart_label: 'Manquant',
        ecart_dot_class: 'bg-orange-500',
        ecart_motif: 'Écart constaté à la réception',
        notes: null,
        est_reception_complete: false,
    })),
};

async function ouvrir(
    page: Page,
    options: {
        transfert?: Partial<typeof transfert>;
        admin?: boolean;
        advance?: boolean;
        receive?: boolean;
        cancel?: boolean;
        update?: boolean;
        dark?: boolean;
    } = {},
) {
    const manifest = JSON.parse(
        await readFile('public/build/manifest.json', 'utf8'),
    );
    const entry = manifest['resources/js/app.ts'];
    const initialPage = {
        component: 'Logistique/Show',
        url: '/backoffice/logistique/17',
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
                permissions: { 'logistique.read': true },
            },
            theme: {
                active: { preset: 'aura', primary: 'blue', surface: 'slate' },
                allowed: {},
                locked: {},
            },
            transfert: { ...transfert, ...options.transfert },
            contexte: 'transferts',
            types_ecart: [
                { value: 'conforme', label: 'Conforme' },
                { value: 'manquant', label: 'Manquant' },
                { value: 'surplus', label: 'Surplus' },
            ],
            can_avancer: options.advance ?? false,
            can_valider_reception: options.receive ?? false,
            can_annuler: options.cancel ?? false,
            can_update: options.update ?? false,
            can_valider_reception_admin: options.admin ?? false,
            activites: Array.from({ length: 25 }, (_, i) => ({
                id: i,
                action: i ? 'statut_avance' : 'creation',
                action_label: i
                    ? 'a validé une étape du transfert'
                    : 'a créé le transfert',
                user_nom: 'Moussa SIDIBÉ',
                details: null,
                created_at: `07/09/2026 ${10 + Math.floor(i / 6)}:00`,
            })),
        },
    };
    await page.addInitScript(
        (appearance) => localStorage.setItem('appearance', appearance),
        options.dark ? 'dark' : 'light',
    );
    const data = JSON.stringify(initialPage)
        .replaceAll('&', '&amp;')
        .replaceAll('"', '&quot;')
        .replaceAll('<', '&lt;');
    const html = `<!doctype html><html lang="fr"><head><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">${entry.css.map((file: string) => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><div id="app" data-page="${data}"></div><script type="module" src="/build/${entry.file}"></script></body></html>`;
    const publicRoot = path.resolve('public');
    const payloads: { url: string; body: Record<string, unknown> }[] = [];
    await page.route('**/*', async (route) => {
        const request = route.request();
        const pathname = new URL(request.url()).pathname;
        if (pathname.startsWith('/backoffice/logistique')) {
            if (request.method() === 'POST')
                payloads.push({ url: pathname, body: request.postDataJSON() });
            return request.headers()['x-inertia']
                ? route.fulfill({
                      headers: { 'X-Inertia': 'true' },
                      json: initialPage,
                  })
                : route.fulfill({
                      contentType: 'text/html; charset=utf-8',
                      body: html,
                  });
        }
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
    await page.goto('http://ui-preview.test/backoffice/logistique/17');
    await expect(page.getByRole('tab', { name: 'Informations' })).toBeVisible();
    return payloads;
}

async function sansDebordement(page: Page) {
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
}

async function panneauStable(dialog: Locator) {
    await expect(dialog).toBeVisible();
    await dialog.evaluate(async (el) => {
        await Promise.allSettled(
            el.getAnimations().map((animation) => animation.finished),
        );
    });
}

for (const width of [360, 390, 413, 440]) {
    test(`mobile ${width} : jalons défilables, onglets et cartes produits`, async ({
        page,
    }, info) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));
        await page.setViewportSize({ width, height: 802 });
        await ouvrir(page);
        await expect(
            page.getByTestId('logistique-mobile-header'),
        ).toBeVisible();
        expect(
            (await page
                .getByRole('button', { name: 'Actions du transfert' })
                .boundingBox())!.height,
        ).toBeGreaterThanOrEqual(44);
        const scroller = page.getByTestId('logistique-jalons');
        await expect
            .poll(() => scroller.evaluate((el) => el.scrollLeft))
            .toBeGreaterThan(0);
        const current = (await page
            .getByTestId('stepper-step-commission')
            .boundingBox())!;
        const box = (await scroller.boundingBox())!;
        expect(current.x).toBeGreaterThanOrEqual(box.x);
        expect(current.x + current.width).toBeLessThanOrEqual(
            box.x + box.width,
        );
        await sansDebordement(page);
        await page.screenshot({ path: info.outputPath('informations.png') });
        await scroller.evaluate((el) => {
            el.scrollLeft = 0;
        });
        await expect
            .poll(() => scroller.evaluate((el) => el.scrollLeft))
            .toBe(0);
        await scroller.evaluate((el) => {
            el.scrollLeft = el.scrollWidth;
        });
        const last = (await page
            .getByTestId('stepper-step-cloture')
            .boundingBox())!;
        expect(last.x + last.width).toBeLessThanOrEqual(box.x + box.width);
        await page.getByRole('tab', { name: 'Produits', exact: true }).click();
        const rows = page.getByRole('tabpanel').locator('tbody tr');
        await expect(rows).toHaveCount(4);
        expect(
            await rows.first().evaluate((el) => getComputedStyle(el).display),
        ).toBe('grid');
        await sansDebordement(page);
        await page
            .getByRole('button', { name: /^Voir la photo/ })
            .first()
            .click();
        await expect(
            page.getByRole('button', { name: 'Fermer la photo' }),
        ).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(
            page.getByRole('button', { name: 'Fermer la photo' }),
        ).toHaveCount(0);
        await page.screenshot({ path: info.outputPath('produits.png') });
        await page
            .getByRole('tab', { name: 'Commission', exact: true })
            .click();
        await expect(page.getByRole('tabpanel')).toContainText('30 000 GNF');
        await expect(page.getByRole('tabpanel')).toContainText('80 000 GNF');
        await sansDebordement(page);
        await page.screenshot({ path: info.outputPath('commission.png') });
        expect(errors).toEqual([]);
    });
}

test('historique : panneau mobile, contenu défilant et fermeture accessible', async ({
    page,
}, info) => {
    await page.setViewportSize({ width: 413, height: 500 });
    await ouvrir(page);
    await page.getByRole('button', { name: 'Actions du transfert' }).click();
    await page.getByRole('menuitem', { name: /Historique/ }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toContainText('Historique du transfert');
    await panneauStable(dialog);
    await expect
        .poll(async () => (await dialog.boundingBox())?.width)
        .toBe(413);
    const box = (await dialog.boundingBox())!;
    expect(box.y + box.height).toBeLessThanOrEqual(500);
    expect(box.height).toBeLessThanOrEqual(450);
    await dialog.getByText('a créé le transfert').scrollIntoViewIfNeeded();
    await page.screenshot({ path: info.outputPath('historique.png') });
    const close = dialog.locator('.p-dialog-header-actions button');
    expect((await close.boundingBox())!.height).toBeGreaterThanOrEqual(44);
    await close.click();
    await expect(dialog).toHaveCount(0);
});

test('annulation : confirmation et POST seulement après accord explicite', async ({
    page,
}) => {
    const payloads = await ouvrir(page, {
        cancel: true,
        advance: true,
        update: true,
        transfert: {
            statut: 'brouillon',
            statut_label: 'Brouillon',
            is_brouillon: true,
            is_editable: true,
            commission_generique_genere: false,
        },
    });
    await expect(page.getByTestId('logistique-primary-action')).toHaveCount(1);
    await page.getByRole('button', { name: 'Actions du transfert' }).click();
    await expect(
        page.getByRole('menuitem', { name: 'Modifier' }),
    ).toHaveAttribute('href', '/backoffice/logistique/17/editer');
    await page.getByRole('menuitem', { name: 'Annuler le transfert' }).click();
    expect(payloads).toHaveLength(0);
    await page.getByRole('button', { name: 'Conserver le transfert' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await page.getByRole('button', { name: 'Actions du transfert' }).click();
    await page.getByRole('menuitem', { name: 'Annuler le transfert' }).click();
    await page.getByRole('button', { name: 'Confirmer l’annulation' }).click();
    await expect.poll(() => payloads.length).toBe(1);
    expect(payloads[0]).toEqual({
        url: '/backoffice/logistique/17/statut/annuler',
        body: {},
    });
});

test('chargement : cartes de saisie, bouton visible et quantités transmises', async ({
    page,
}, info) => {
    await page.setViewportSize({ width: 360, height: 500 });
    const payloads = await ouvrir(page, {
        advance: true,
        transfert: {
            statut: 'chargement',
            statut_label: 'Chargement',
            commission_generique_genere: false,
        },
    });
    await page.getByTestId('logistique-primary-action').click();
    const dialog = page.getByRole('dialog');
    const input = dialog.locator('#chargement-qte-1');
    await panneauStable(dialog);
    await input.fill('850');
    await input.blur();
    expect((await input.boundingBox())!.height).toBeGreaterThanOrEqual(44);
    const submit = dialog.getByRole('button', {
        name: 'Valider et partir en livraison',
    });
    expect(
        (await submit.boundingBox())!.y + (await submit.boundingBox())!.height,
    ).toBeLessThanOrEqual(500);
    expect(
        await dialog.evaluate((el) => el.scrollWidth <= el.clientWidth),
    ).toBe(true);
    await page.screenshot({ path: info.outputPath('chargement.png') });
    await submit.click();
    await expect.poll(() => payloads.length).toBe(1);
    expect(
        (
            payloads[0].body.lignes as {
                id: number;
                quantite_chargee: number;
            }[]
        )[0],
    ).toEqual({ id: 1, quantite_chargee: 850 });
});

test('réception : saisie mobile, motif et valeurs métier conservés', async ({
    page,
}, info) => {
    await page.setViewportSize({ width: 390, height: 500 });
    const payloads = await ouvrir(page, {
        receive: true,
        transfert: {
            statut: 'transit',
            statut_label: 'En livraison',
            commission_generique_genere: false,
        },
    });
    await page.getByTestId('logistique-primary-action').click();
    const dialog = page.getByRole('dialog');
    await panneauStable(dialog);
    await dialog.locator('#reception-qte-1').fill('950');
    await dialog.locator('#reception-qte-1').blur();
    await dialog
        .getByRole('textbox', { name: /^Motif/ })
        .first()
        .fill('Dix cartons non réceptionnés');
    expect(
        await dialog.evaluate((el) => el.scrollWidth <= el.clientWidth),
    ).toBe(true);
    await page.screenshot({ path: info.outputPath('reception.png') });
    await dialog
        .getByRole('button', { name: 'Valider la réception', exact: true })
        .click();
    await expect.poll(() => payloads.length).toBe(1);
    expect((payloads[0].body.lignes as Record<string, unknown>[])[0]).toEqual({
        id: 1,
        quantite_recue: 950,
        ecart_type: 'manquant',
        ecart_motif: 'Dix cartons non réceptionnés',
    });
});

test('approbation : action unique, données reçues et décision serveur', async ({
    page,
}, info) => {
    await page.setViewportSize({ width: 413, height: 500 });
    const payloads = await ouvrir(page, {
        admin: true,
        transfert: {
            validation_reception: null,
            commission_generique_genere: false,
        },
    });
    await page.getByTestId('logistique-primary-action').click();
    const dialog = page.getByRole('dialog');
    await panneauStable(dialog);
    await expect(dialog).toContainText('900');
    await expect(dialog).toContainText('Manquant');
    await page.screenshot({ path: info.outputPath('approbation.png') });
    await dialog.getByRole('button', { name: 'Oui, approuver' }).click();
    await expect.poll(() => payloads.length).toBe(1);
    expect(payloads[0]).toEqual({
        url: '/backoffice/logistique/17/validation-reception',
        body: { decision: 'accord' },
    });
});

test('lecture seule et onglets au clavier : aucune action métier ajoutée', async ({
    page,
}) => {
    await ouvrir(page, {
        transfert: {
            statut: 'transit',
            statut_label: 'En livraison',
            commission_generique_genere: false,
        },
    });
    await expect(page.getByTestId('logistique-primary-action')).toHaveCount(0);
    await expect(
        page.getByRole('tab', { name: 'Commission', exact: true }),
    ).toBeDisabled();
    const tab = page.getByRole('tab', { name: 'Informations' });
    await tab.focus();
    await tab.press('ArrowRight');
    await expect(
        page.getByRole('tab', { name: 'Produits', exact: true }),
    ).toBeFocused();
    await page.getByRole('button', { name: 'Actions du transfert' }).click();
    await expect(
        page.getByRole('menuitem', { name: /Annuler|Modifier/ }),
    ).toHaveCount(0);
});

for (const width of [768, 1440]) {
    test(`tablette / bureau ${width} : jalons complets et tableau conservés`, async ({
        page,
    }, info) => {
        await page.setViewportSize({ width, height: 900 });
        await ouvrir(page);
        await expect(page.getByTestId('logistique-mobile-header')).toBeHidden();
        const steps = page.getByTestId('logistique-jalons');
        expect(
            await steps.evaluate((el) => el.scrollWidth <= el.clientWidth),
        ).toBe(true);
        await page
            .getByRole('tab', { name: 'Lignes produits', exact: true })
            .click();
        expect(
            await page
                .getByRole('tabpanel')
                .locator('tbody tr')
                .first()
                .evaluate((el) => getComputedStyle(el).display),
        ).toBe('table-row');
        await sansDebordement(page);
        await page.screenshot({ path: info.outputPath('bureau.png') });
    });
}

test('mobile sombre, clôture et retour de tablette : jalons et statut conservés', async ({
    page,
}, info) => {
    await ouvrir(page, {
        dark: true,
        transfert: {
            statut: 'cloture',
            statut_label: 'Clôturé',
            is_cloture: true,
            is_terminal: true,
            commission_statut: 'paye',
            commission_statut_label: 'Payé',
        },
    });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.setViewportSize({ width: 768, height: 900 });
    await page.setViewportSize({ width: 390, height: 844 });
    const steps = page.getByTestId('logistique-jalons');
    await expect
        .poll(() => steps.evaluate((el) => el.scrollLeft))
        .toBeGreaterThan(0);
    await expect(page.getByTestId('logistique-primary-action')).toHaveCount(0);
    await sansDebordement(page);
    await page.screenshot({ path: info.outputPath('sombre.png') });
});
