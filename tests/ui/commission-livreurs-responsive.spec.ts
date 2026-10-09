import { expect, test, type Page } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const beneficiaires = [
    {
        beneficiaire_id: 'livreur-1',
        beneficiaire_nom:
            'Chauffeur-1 Thierno-Moto avec un nom particulièrement long',
        telephone: '666177008',
        agence: 'Agence de Matoto avec un nom particulièrement long',
        vehicules: [
            {
                id: 'vehicule-1',
                nom: 'Thierno-Moto livraison grande agence',
                immatriculation: 'OU124',
                type: 'Moto',
                capacites: [],
                proprietaire_nom: 'Issa',
                proprietaire_telephone: null,
            },
            {
                id: 'vehicule-2',
                nom: 'Abarry',
                immatriculation: 'AI3462',
                type: 'Camion',
                capacites: [],
                proprietaire_nom: 'Issa',
                proprietaire_telephone: null,
            },
        ],
        total_genere: 123456789,
        total_brut_cumule: 120000000,
        total_frais: 5000,
        total_net_cumule: 119995000,
        total_verse: 10000000,
        solde_restant: 109995000,
        remaining_amount: 109995000,
        nb_commandes: 3,
        statut_global: 'creee',
        display_status: 'creee',
        display_label: 'À valider',
        can_pay: false,
        creee_parts: [{ id: 'part-1', montant: 123456789 }],
        processus_labels: [
            'Vente',
            'Transfert logistique',
            'Transfert grossiste',
        ],
        fiche_a_payer: null,
    },
    {
        beneficiaire_id: 'livreur-2',
        beneficiaire_nom: 'Saa Fodé',
        telephone: null,
        agence: null,
        vehicules: [],
        total_brut_cumule: 190000,
        total_frais: 0,
        total_net_cumule: 190000,
        total_verse: 190000,
        solde_restant: 0,
        remaining_amount: 0,
        nb_commandes: 1,
        statut_global: 'paye',
        display_status: 'paye',
        display_label: 'Payé',
        can_pay: false,
        creee_parts: [],
        processus_labels: [],
        fiche_a_payer: null,
    },
];

async function ouvrir(page: Page, dark = false) {
    const manifest = JSON.parse(
        await readFile('public/build/manifest.json', 'utf8'),
    );
    const entry = manifest['resources/js/app.ts'];
    const url = '/backoffice/comptabilite/commissions/vente';
    const data = {
        component: 'Comptabilite/CommissionVente/Index',
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
                roles: [],
                sites: [],
                permissions: { 'commissions.read': true },
            },
            theme: {
                active: { preset: 'aura', primary: 'blue', surface: 'slate' },
                allowed: {},
                locked: {},
            },
            beneficiaires,
            kpis: {
                nb_livreurs: 2,
                total_brut: 120190000,
                total_frais: 5000,
                total_net: 120185000,
                total_verse: 10190000,
                solde_total: 109995000,
                total_genere: 123646789,
            },
            search: '',
            filtre_statut: '',
            filtre_site_ids: [],
            filtre_processus: [],
            processus_options: [],
            selected_periode: '',
            periodes_disponibles: [],
            periode_courante: '',
            periode_affichee: null,
            sites: [],
            motifs: [],
            can_payer: false,
        },
    };
    const escaped = JSON.stringify(data)
        .replaceAll('&', '&amp;')
        .replaceAll('"', '&quot;')
        .replaceAll('<', '&lt;');
    const html = `<!doctype html><html lang="fr"><head><meta name="viewport" content="width=device-width,initial-scale=1">${entry.css.map((file: string) => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><div id="app" data-page="${escaped}"></div><script type="module" src="/build/${entry.file}"></script></body></html>`;
    await page.addInitScript(
        (appearance) => localStorage.setItem('appearance', appearance),
        dark ? 'dark' : 'light',
    );
    const publicRoot = path.resolve('public');
    await page.route('**/*', async (route) => {
        const pathname = new URL(route.request().url()).pathname;
        if (pathname === url)
            return route.fulfill({
                contentType: 'text/html; charset=utf-8',
                body: html,
            });
        if (pathname.startsWith('/backoffice/saved-filters'))
            return route.fulfill({ json: { data: [] } });
        const file = path.resolve(publicRoot, '.' + pathname);
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
    await page.goto(`http://ui-preview.test${url}`);
    await expect(page.getByTestId('livreur-commissions-table')).toBeVisible();
}

for (const width of [360, 390, 768, 1024, 1440, 1920]) {
    test(`toutes les données restent lisibles à ${width}px`, async ({
        page,
    }, testInfo) => {
        const errors: string[] = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await page.setViewportSize({ width, height: 1080 });
        await ouvrir(page);
        const table = page.getByTestId('livreur-commissions-table');
        const row = table.locator('tbody tr').first();
        const scroller = page.getByTestId('commission-table-scroll');
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
        expect(
            await scroller.evaluate((el) => el.scrollWidth <= el.clientWidth),
        ).toBe(true);
        for (const text of [
            '123 456 789 GNF',
            '120 000 000 GNF',
            '-5 000 GNF',
            '119 995 000 GNF',
            '10 000 000 GNF',
            '109 995 000 GNF',
        ]) {
            const amount = row.getByText(text, { exact: true });
            await expect(amount).toBeVisible();
            const fits = await amount.evaluate((el) => {
                const bounds = el.getBoundingClientRect();
                const cell = el.closest('td')!.getBoundingClientRect();
                const range = document.createRange();
                range.selectNodeContents(el);
                return (
                    bounds.left >= cell.left &&
                    bounds.right <= cell.right + 1 &&
                    range.getClientRects().length === 1
                );
            });
            expect(fits, text).toBe(true);
        }
        for (const value of [
            'Généré',
            'Brut',
            'Dépenses',
            'Net à payer',
            'Déjà payé',
            'Reste à payer',
            'À valider',
            'Vente',
            'Transfert logistique',
            'Transfert grossiste',
            'OU124',
            'AI3462',
            beneficiaires[0].beneficiaire_nom,
            beneficiaires[0].agence!,
        ]) {
            await expect(row.getByText(value, { exact: true })).toBeVisible();
        }
        const rowDisplay = await row.evaluate(
            (el) => getComputedStyle(el).display,
        );
        expect(rowDisplay).toBe(width >= 1440 ? 'table-row' : 'grid');
        await table
            .getByRole('checkbox', { name: 'Tout sélectionner' })
            .click();
        await expect(
            page.getByRole('button', { name: 'Valider la sélection' }),
        ).toBeVisible();
        await expect(row.getByRole('checkbox')).toBeChecked();
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
        await expect(
            table.locator('tbody tr').nth(1).getByRole('checkbox'),
        ).toBeDisabled();
        await page
            .getByRole('button', { name: 'Annuler', exact: true })
            .click();
        await row.getByRole('button', { name: 'Actions pour' }).click();
        await expect(
            page.getByRole('menuitem', { name: 'Historique' }),
        ).toBeVisible();
        await expect(
            page.getByRole('menuitem', { name: 'Ajuster' }),
        ).toBeVisible();
        await page.keyboard.press('Escape');
        await row
            .getByRole('button', {
                name: 'Thierno-Moto livraison grande agence',
            })
            .click();
        await expect(page.getByRole('dialog')).toContainText('OU124');
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
        await page
            .getByRole('dialog')
            .getByText('Fermer', { exact: true })
            .click();
        await expect(page.getByRole('dialog')).toBeHidden();
        await page.screenshot({
            path: testInfo.outputPath('commissions.png'),
            fullPage: true,
        });
        expect(errors).toEqual([]);
    });
}

for (const width of [390, 1920]) {
    test(`mode sombre à ${width}px`, async ({ page }, testInfo) => {
        await page.setViewportSize({ width, height: 1080 });
        await ouvrir(page, true);
        await expect(page.locator('html')).toHaveClass(/dark/);
        expect(
            await page
                .getByTestId('commission-table-scroll')
                .evaluate((el) => el.scrollWidth <= el.clientWidth),
        ).toBe(true);
        await page.screenshot({
            path: testInfo.outputPath('commissions-sombre.png'),
            fullPage: true,
        });
    });
}
