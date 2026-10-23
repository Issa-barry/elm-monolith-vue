import { expect, test } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

const commande = {
    id: 'commande-test',
    reference: 'CMD-211026-001',
    statut: 'brouillon',
    statut_label: 'Brouillon',
    statut_affichage: { value: 'brouillon', label: 'Brouillon' },
    total_commande: 3000,
    lignes: [],
    factures: [],
    receptions: [],
    is_brouillon: true,
    created_at: '21/10/2026',
    date_achat: '2026-10-21',
    moyens_encaissement: [],
};
const facture = {
    id: 'facture-test',
    reference: 'FAC-211026-001',
    date_facture: '2026-10-21',
    taux_tva: 0,
    montant_ht: 3000,
    montant_tva: 0,
    montant_ttc: 3000,
    montant_paye: 3000,
    reste_du: 0,
    montant_net: 3000,
    montant_encaisse: 3000,
    montant_restant: 0,
    statut: 'validee',
    statut_facture: 'payee',
    statut_label: 'Payée',
    statut_affichage: 'payee',
    is_payee: true,
    is_annulee: false,
    is_encaissable: false,
    receptions: [],
    lignes: [],
    encaissements: [],
};
const documents = [
    {
        nom: 'facture d’achat',
        component: 'Achats/Factures/Show',
        url: '/backoffice/achats/factures/facture-test',
        pdf: '/backoffice/achats/factures/facture-test/pdf?preview=1',
        props: {
            facture,
            commande,
            comptabilite: { statut: 'comptabilisee', label: 'Comptabilisée' },
            paiements: [],
            paiement: null,
            actions: {},
        },
    },
    {
        nom: 'bon de commande d’achat',
        component: 'Achats/Show',
        url: '/backoffice/achats/commande-test',
        pdf: '/backoffice/achats/commande-test/pdf?preview=1',
        props: {
            commande,
            actions: {},
            aucun_validateur_disponible: false,
            jalons: { etapes: [], autres_actions: [] },
            validable_par: [],
        },
    },
    {
        nom: 'facture de vente',
        component: 'Factures/Index',
        url: '/backoffice/factures',
        pdf: '/backoffice/factures/facture-test/pdf?preview=1',
        props: {
            factures: [facture],
            totaux: { nb_total: 1, total: 3000 },
            periode: 'tout',
            statut: 'tous',
            sites: [],
        },
    },
    {
        nom: 'bon de commande de vente',
        component: 'Ventes/Show',
        url: '/backoffice/ventes/commande-test',
        pdf: '/backoffice/ventes/commande-test/pdf?preview=1',
        props: {
            commande,
            facture: null,
            commission_statut: null,
            commission_generation_statut: null,
            historiques: [],
            activites: [],
            retours: [],
            motifs_retour: [],
            precommande: null,
        },
    },
];

// Vrai bundle, données fictives. Les Feature tests vérifient le PDF inline réel ;
// ici une page témoin permet de vérifier l'ouverture et le maintien de la page source.
for (const width of [390, 1440]) {
    for (const document of documents) {
        test(`${document.nom} : nouvel onglet à ${width}px`, async ({
            page,
            context,
        }) => {
            await page.setViewportSize({ width, height: 900 });
            const errors: string[] = [];
            page.on('pageerror', (error) => errors.push(error.message));
            const manifest = JSON.parse(
                await readFile('public/build/manifest.json', 'utf8'),
            );
            const entry = manifest['resources/js/app.ts'];
            const data = JSON.stringify({
                component: document.component,
                url: document.url,
                version: null,
                props: {
                    errors: {},
                    flash: {},
                    name: 'ELM',
                    sidebarOpen: false,
                    auth: {
                        user: { id: 'preview', name: 'Aperçu UI' },
                        roles: [],
                        sites: [],
                        permissions: { 'ventes.read': true },
                    },
                    theme: {
                        active: {
                            preset: 'aura',
                            primary: 'blue',
                            surface: 'slate',
                        },
                        allowed: {},
                        locked: {},
                    },
                    ...document.props,
                },
            })
                .replaceAll('&', '&amp;')
                .replaceAll('"', '&quot;')
                .replaceAll('<', '&lt;');
            const html = `<!doctype html><html lang="fr"><head><meta name="viewport" content="width=device-width,initial-scale=1">${entry.css.map((file: string) => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><div id="app" data-page="${data}"></div><script type="module" src="/build/${entry.file}"></script></body></html>`;
            const publicRoot = path.resolve('public');
            await context.route('**/*', async (route) => {
                const url = new URL(route.request().url());
                if (url.pathname + url.search === document.pdf) {
                    return route.fulfill({
                        contentType: 'text/html; charset=utf-8',
                        body: '<h1>Aperçu du document</h1>',
                    });
                }
                if (url.pathname === document.url) {
                    return route.fulfill({
                        contentType: 'text/html; charset=utf-8',
                        body: html,
                    });
                }
                if (url.pathname.startsWith('/backoffice/saved-filters')) {
                    return route.fulfill({ json: { data: [] } });
                }
                const file = path.resolve(publicRoot, '.' + url.pathname);
                if (!file.startsWith(publicRoot + path.sep))
                    return route.abort();
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
            await page.goto(`http://ui-preview.test${document.url}`);
            if (document.component === 'Factures/Index' && width > 640) {
                await page
                    .getByRole('button', {
                        name: `Actions de la facture ${facture.reference}`,
                    })
                    .click();
            }
            if (document.component === 'Ventes/Show' && width < 640) {
                await page.locator('.sticky [aria-haspopup="menu"]').click();
            }
            const link = page.locator(`a[href="${document.pdf}"]:visible`);
            await expect(link).toBeVisible();
            await expect(link).toHaveAttribute('target', '_blank');
            await expect(link).toHaveAttribute('rel', 'noopener noreferrer');
            await expect(link).not.toHaveAttribute('download');
            const [preview] = await Promise.all([
                context.waitForEvent('page'),
                link.click(),
            ]);
            await expect(preview).toHaveURL(
                `http://ui-preview.test${document.pdf}`,
            );
            await expect(
                preview.getByRole('heading', { name: 'Aperçu du document' }),
            ).toBeVisible();
            await expect(page).toHaveURL(
                `http://ui-preview.test${document.url}`,
            );
            expect(errors).toEqual([]);
            await preview.close();
        });
    }
}
