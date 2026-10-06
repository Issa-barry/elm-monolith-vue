import { expect, test, type Page } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const detail = {
    id: 'depense-detail',
    date_depense: '2026-09-06',
    montant: 5000,
    montant_formatte: '5 000',
    statut: 'valide',
    statut_label: 'Validée',
    commentaire: null,
    motif_rejet: null,
    commentaire_rejet: null,
    justificatif_path: null,
    date_validation: '2026-09-06T10:00:00',
    created_at: '2026-09-06T09:00:00',
    type_libelle: 'Avance sur salaire',
    categorie: 'livreur',
    categorie_label: 'Livreur',
    impact_message:
        'Cette dépense sera déduite de la commission quinzaine du livreur sélectionné.',
    vehicule_nom: null,
    vehicule_immatriculation: null,
    beneficiaire_label: 'Saa Fodé',
    site_nom: 'Matoto',
    saisi_par: 'Moussa SIDIBÉ',
    validateur: 'Moussa SIDIBÉ',
    imputations: [
        {
            id: 'imputation',
            imputation_type: 'commission_livreur',
            beneficiaire_type: 'livreur',
            beneficiaire_label: 'Saa Fodé',
            montant: 5000,
            periode_type: 'quinzaine',
            periode_debut: '2026-09-01',
            periode_fin: '2026-09-15',
            statut: 'impute',
        },
    ],
    can_edit: true,
    can_submit: false,
    can_validate: false,
    can_reject: false,
    can_delete: true,
};

async function mountDetail(
    page: Page,
    options: { depense?: Partial<typeof detail>; dark?: boolean } = {},
) {
    const manifest = JSON.parse(
        await readFile('public/build/manifest.json', 'utf8'),
    );
    const entry = manifest['resources/js/app.ts'];
    const initialPage = {
        component: 'Depenses/Show',
        url: '/backoffice/depenses/depense-detail',
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
                permissions: { 'depenses.read': true },
            },
            theme: {
                active: { preset: 'aura', primary: 'blue', surface: 'slate' },
                allowed: {},
                locked: {},
            },
            depense: { ...detail, ...options.depense },
        },
    };
    const data = JSON.stringify(initialPage)
        .replaceAll('&', '&amp;')
        .replaceAll('"', '&quot;')
        .replaceAll('<', '&lt;');
    const html = `<!doctype html><html lang="fr"><head><meta name="viewport" content="width=device-width,initial-scale=1">${entry.css.map((file: string) => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><div id="app" data-page="${data}"></div><script type="module" src="/build/${entry.file}"></script></body></html>`;
    const publicRoot = path.resolve('public');
    await page.addInitScript(
        (appearance) => localStorage.setItem('appearance', appearance),
        options.dark ? 'dark' : 'light',
    );
    await page.route('**/*', async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        if (url.pathname.endsWith('/depense-detail/historique')) {
            return route.fulfill({
                json: {
                    logs: [
                        {
                            id: 'log-validation',
                            date: '06/09/2026 10:00',
                            acteur: 'Moussa SIDIBÉ',
                            event_code: 'validated',
                            action: 'Validation',
                            description:
                                'Dépense validée avec un commentaire particulièrement long et une référence ' +
                                'A'.repeat(90),
                        },
                    ],
                },
            });
        }
        if (url.pathname.startsWith('/backoffice/depenses')) {
            if (request.headers()['x-inertia']) {
                return route.fulfill({
                    contentType: 'application/json',
                    headers: { 'X-Inertia': 'true' },
                    body: JSON.stringify({ ...initialPage, url: url.pathname }),
                });
            }
            return route.fulfill({
                contentType: 'text/html; charset=utf-8',
                body: html,
            });
        }
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
    await page.goto(
        'http://ui-preview.test/backoffice/depenses/depense-detail',
    );
}

async function expectNoOverflow(page: Page) {
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
}

for (const width of [360, 390, 413, 440]) {
    test(`fiche Dépense : en-tête et actions à ${width}px`, async ({
        page,
    }, testInfo) => {
        await page.setViewportSize({ width, height: 802 });
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await mountDetail(page);
        const header = page.getByTestId('depense-detail-header');
        await expect(
            header.getByRole('heading', { name: 'Dépense', exact: true }),
        ).toBeVisible();
        const edit = header.getByRole('link', {
            name: 'Modifier',
            exact: true,
        });
        await expect(edit).toHaveAttribute(
            'href',
            '/backoffice/depenses/depense-detail/edit',
        );
        for (const control of [
            edit,
            header.getByRole('link', { name: 'Retour aux dépenses' }),
            header.getByRole('button', { name: 'Autres actions' }),
            page.getByRole('button', { name: 'Informations', exact: true }),
            page.getByRole('button', { name: 'Historique', exact: true }),
        ]) {
            const box = await control.boundingBox();
            expect(box!.height).toBeGreaterThanOrEqual(44);
        }
        const headerBox = (await header.boundingBox())!;
        const editBox = (await edit.boundingBox())!;
        expect(headerBox.y).toBe(0);
        expect(editBox.y + editBox.height).toBeLessThanOrEqual(
            headerBox.y + headerBox.height,
        );
        await expect(
            page.getByRole('heading', {
                name: detail.type_libelle,
                exact: true,
            }),
        ).toBeVisible();
        await expect(
            page
                .getByText('Saisie le', { exact: false })
                .filter({ visible: true }),
        ).toContainText(detail.saisi_par);
        await expect(
            page.getByRole('button', { name: 'Supprimer', exact: false }),
        ).toHaveCount(0);
        await expectNoOverflow(page);
        await page.screenshot({
            path: testInfo.outputPath('depense-detail-mobile.png'),
            fullPage: true,
        });
        await header.getByRole('button', { name: 'Autres actions' }).click();
        const remove = page.getByRole('menuitem', {
            name: 'Supprimer la dépense',
        });
        await expect(remove).toBeVisible();
        await expect
            .poll(async () => (await remove.boundingBox())!.height)
            .toBeGreaterThanOrEqual(44);
        await page.keyboard.press('Escape');
        await expect(
            header.getByRole('button', { name: 'Autres actions' }),
        ).toBeFocused();
        await page.setViewportSize({ width, height: 400 });
        await page.evaluate(() =>
            window.scrollTo(0, document.body.scrollHeight),
        );
        expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(0);
        expect((await header.boundingBox())!.y).toBe(0);
        await expect(edit).toBeVisible();
        expect(errors).toEqual([]);
    });
}

test('suppression : confirmation conservée et bon identifiant', async ({
    page,
}) => {
    await mountDetail(page);
    let deleteCount = 0;
    page.on('request', (request) => {
        if (request.method() === 'DELETE') deleteCount++;
    });
    await page.getByRole('button', { name: 'Autres actions' }).click();
    page.once('dialog', async (dialog) => {
        expect(dialog.message()).toBe('Supprimer cette dépense ?');
        await dialog.dismiss();
    });
    await page.getByRole('menuitem', { name: 'Supprimer la dépense' }).click();
    await expect(page.getByRole('menu')).toBeHidden();
    expect(deleteCount).toBe(0);
    await page.getByRole('button', { name: 'Autres actions' }).click();
    page.once('dialog', (dialog) => dialog.accept());
    const deletion = page.waitForRequest((r) => r.method() === 'DELETE');
    await page.getByRole('menuitem', { name: 'Supprimer la dépense' }).click();
    expect(new URL((await deletion).url()).pathname).toBe(
        '/backoffice/depenses/depense-detail',
    );
});

test('permissions serveur : fiche en consultation seule', async ({ page }) => {
    await mountDetail(page, {
        depense: { can_edit: false, can_delete: false },
    });
    await expect(page.getByRole('link', { name: 'Modifier' })).toHaveCount(0);
    for (const name of ['Autres actions', 'Soumettre', 'Valider', 'Rejeter']) {
        await expect(
            page.getByRole('button', { name, exact: true }),
        ).toHaveCount(0);
    }
    await expect(
        page.getByRole('heading', { name: detail.type_libelle, exact: true }),
    ).toBeVisible();
});

test('parcours : modification, soumission, validation et rejet', async ({
    page,
}) => {
    await mountDetail(page);
    await page.getByRole('link', { name: 'Modifier', exact: true }).click();
    await expect(page).toHaveURL(/\/depense-detail\/edit$/);
    for (const [label, action, flags] of [
        ['Soumettre', 'soumettre', { can_submit: true, statut: 'brouillon' }],
        ['Valider', 'valider', { can_validate: true, statut: 'soumis' }],
    ] as const) {
        await mountDetail(page, { depense: flags });
        const request = page.waitForRequest((r) => r.method() === 'PATCH');
        await page.getByRole('button', { name: label, exact: true }).click();
        expect(new URL((await request).url()).pathname).toBe(
            `/backoffice/depenses/depense-detail/${action}`,
        );
    }
    await page.setViewportSize({ width: 360, height: 640 });
    await mountDetail(page, {
        depense: { can_reject: true, statut: 'soumis' },
    });
    await page.getByRole('button', { name: 'Rejeter', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await dialog
        .getByRole('button', { name: 'Rejeter la dépense', exact: true })
        .click();
    await expect(
        dialog.getByText('Le motif de rejet est obligatoire.'),
    ).toBeVisible();
    await dialog.getByLabel('Motif de rejet').selectOption('Autre');
    await dialog.getByLabel('Commentaire').fill('Justificatif incomplet');
    const rejection = page.waitForRequest((r) => r.method() === 'PATCH');
    await dialog
        .getByRole('button', { name: 'Rejeter la dépense', exact: true })
        .click();
    expect((await rejection).postDataJSON()).toEqual({
        motif_rejet: 'Autre',
        commentaire_rejet: 'Justificatif incomplet',
    });
    await expect(dialog).toBeHidden();
});

test('historique : lecture mobile et tableau ordinateur', async ({
    page,
}, testInfo) => {
    await mountDetail(page);
    const historyRequest = page.waitForRequest((r) =>
        r.url().endsWith('/depense-detail/historique'),
    );
    await page.getByRole('button', { name: 'Historique', exact: true }).click();
    await historyRequest;
    await expect(page.getByTestId('depense-mobile-history')).toContainText(
        'Validation',
    );
    await expect(page.getByTestId('depense-mobile-history')).toContainText(
        'Moussa SIDIBÉ',
    );
    await expect(page.getByTestId('depense-desktop-history')).toBeHidden();
    await expectNoOverflow(page);
    await page.screenshot({
        path: testInfo.outputPath('depense-history-mobile.png'),
        fullPage: true,
    });
    await page.setViewportSize({ width: 1440, height: 900 });
    await expect(page.getByTestId('depense-desktop-history')).toBeVisible();
    await expect(page.getByTestId('depense-mobile-history')).toBeHidden();
    await expect(
        page.getByRole('link', { name: 'Modifier', exact: true }),
    ).toHaveCount(1);
    await expectNoOverflow(page);
});

test('thème sombre et libellés longs', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 360, height: 802 });
    await mountDetail(page, {
        dark: true,
        depense: {
            type_libelle:
                'Dépense de maintenance pour une intervention particulièrement longue',
            beneficiaire_label:
                'Bénéficiaire avec un nom particulièrement long',
            site_nom: 'Agence ' + 'A'.repeat(90),
            saisi_par: 'Utilisateur avec un nom particulièrement long',
        },
    });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await expect(
        page.getByRole('link', { name: 'Modifier', exact: true }),
    ).toBeVisible();
    await expectNoOverflow(page);
    await page.screenshot({
        path: testInfo.outputPath('depense-detail-dark.png'),
        fullPage: true,
    });
});
