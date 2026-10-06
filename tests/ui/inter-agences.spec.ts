import { expect, test, type Page } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const url = '/backoffice/comptabilite/tresorerie/inter-agences';
const reversement = {
    debiteur: { id: 'cba', nom: 'Cba' },
    creancier: { id: 'matoto', nom: 'Matoto' },
    a_verser: 800_000,
    en_cours_versement: 0,
    verse: 0,
    statut: 'a_verser',
    statut_label: 'À envoyer',
    detail_url: `${url}/cba/matoto`,
};

// Vrai bundle et composants, données fictives : aucun accès à la base locale.
async function ouvrir(page: Page, lignes = [reversement]) {
    const manifest = JSON.parse(
        await readFile('public/build/manifest.json', 'utf8'),
    );
    const entry = manifest['resources/js/app.ts'];
    const initialPage = {
        component: 'Comptabilite/Tresorerie/InterAgences/Index',
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
                permissions: { 'tresorerie.read': true },
            },
            theme: {
                active: { preset: 'aura', primary: 'blue', surface: 'slate' },
                allowed: {},
                locked: {},
            },
            reversements: lignes,
            filters: { site_ids: [] },
            sites: [
                { value: 'cba', label: 'Cba' },
                { value: 'matoto', label: 'Matoto' },
            ],
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
        if (pathname === url) {
            if (route.request().headers()['x-inertia']) {
                const selected = new URL(
                    route.request().url(),
                ).searchParams.getAll('site_ids[]');
                return route.fulfill({
                    contentType: 'application/json',
                    headers: { 'X-Inertia': 'true' },
                    body: JSON.stringify({
                        ...initialPage,
                        url: route.request().url(),
                        props: {
                            ...initialPage.props,
                            filters: { site_ids: selected },
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

for (const width of [390, 768, 1280, 1440]) {
    test(`reversement simple et filtre Agence à ${width}px`, async ({
        page,
    }, testInfo) => {
        await page.setViewportSize({ width, height: 900 });
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await ouvrir(page);
        await expect(
            page.getByRole('heading', { name: 'Reversements entre agences' }),
        ).toBeVisible();
        const ligne = page.getByTestId('reversement');
        await expect(ligne).toHaveCount(1);
        await expect(ligne.getByTestId('agence-verse')).toHaveText('Cba');
        await expect(ligne.getByTestId('agence-recoit')).toHaveText('Matoto');
        await expect(
            page.getByText('800 000 GNF', { exact: true }),
        ).toHaveCount(1);
        await expect(ligne.getByRole('link')).toHaveAttribute(
            'href',
            reversement.detail_url,
        );
        await expect(page.getByTestId('en-cours-versement')).toHaveCount(0);
        await expect(page.getByTestId('deja-verse')).toHaveText('0 GNF');
        await expect(page.getByTestId('statut-reversement')).toContainText(
            'À envoyer',
        );
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
        await page.screenshot({
            path: testInfo.outputPath('reversements.png'),
            fullPage: true,
        });

        await page.getByRole('button', { name: /^Filtres/ }).click();
        const drawer = page.getByRole('dialog', { name: 'Filtres' });
        await expect(drawer).toBeVisible();
        await drawer
            .getByTestId('agency-filter')
            .locator('.p-multiselect-dropdown')
            .click();
        await page.getByRole('option', { name: 'Cba', exact: true }).click();
        await page.keyboard.press('Escape');
        const request = page.waitForRequest((request) =>
            request.url().includes('site_ids'),
        );
        await drawer
            .getByRole('button', { name: 'Appliquer les filtres', exact: true })
            .click();
        expect(
            new URL((await request).url()).searchParams.getAll('site_ids[]'),
        ).toEqual(['cba']);
        await expect(drawer).not.toBeVisible();
        expect(errors).toEqual([]);
    });

    test(`versement en cours distinct à ${width}px`, async ({
        page,
    }, testInfo) => {
        await page.setViewportSize({ width, height: 900 });
        await ouvrir(page, [
            {
                ...reversement,
                a_verser: 200_000,
                en_cours_versement: 600_000,
                verse: 100_000,
                statut: 'en_cours_versement',
                statut_label: 'En cours de versement',
            },
        ]);
        await expect(page.getByTestId('reste-a-verser')).toHaveText(
            '200 000 GNF',
        );
        await expect(page.getByTestId('en-cours-versement')).toHaveText(
            '600 000 GNF',
        );
        await expect(page.getByTestId('deja-verse')).toHaveText('100 000 GNF');
        await expect(page.getByTestId('statut-reversement')).toContainText(
            'En cours de versement',
        );
        expect(
            await page
                .locator('table')
                .evaluate(
                    (table) =>
                        table.getBoundingClientRect().width <=
                        table.parentElement!.clientWidth,
                ),
        ).toBe(true);
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
        await page.screenshot({
            path: testInfo.outputPath('versement-en-cours.png'),
            fullPage: true,
        });
    });
}
