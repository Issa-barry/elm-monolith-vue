import { expect, test } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

// Page Précommandes (ADR 0019) : compteurs du cycle à la place des montants, et identification
// immédiate du véhicule (immatriculation), du livreur et du client (téléphones).

const base = {
    id: '01PRECOMMANDEUI00000000001',
    reference: 'VTE-051026-002',
    statut: 'a_charger',
    statut_label: 'À charger',
    statut_affichage: { value: 'a_charger', label: 'À charger' },
    nature_operation: 'vente_standard',
    processus_code: 'vente',
    processus_label: 'Vente',
    created_at: '05/10/2026',
    est_precommande: true,
    date_remise_prevue: '06/10/2026',
    date_remise_prevue_iso: '2026-10-06',
    en_retard: false,
    precommande_livraison: true,
    trop_percu: 0,
    vehicule_nom: 'Abarry',
    vehicule_immatriculation: 'AI3462',
    vehicule_photo_url: null,
    chauffeur_nom: 'Saa Fodé',
    chauffeur_telephone: '+224613855281',
    client_nom: 'Guirrasy',
    client_telephone: '+224666177001',
    site_nom: 'Matoto',
    total_commande: 10260000,
    quantite_totale: 540,
    facture_id: '01FACTUREUI000000000000001',
    facture_statut: 'creee',
    facture_statut_label: 'Créée',
    facture_montant_encaisse: 2000000,
    facture_montant_restant: 8260000,
    encaissements: [],
    can_modifier: false,
    can_confirmer: false,
    can_annuler: false,
    is_annulee: false,
    is_brouillon: false,
};

const retrait = {
    ...base,
    id: '01PRECOMMANDEUI00000000002',
    reference: 'VTE-031026-001',
    statut: 'reservee',
    statut_label: 'Créée',
    statut_affichage: { value: 'reservee', label: 'Créée' },
    date_remise_prevue: '03/10/2026',
    date_remise_prevue_iso: '2026-10-03',
    en_retard: true,
    precommande_livraison: false,
    vehicule_nom: null,
    vehicule_immatriculation: null,
    chauffeur_nom: null,
    chauffeur_telephone: null,
};

test.beforeEach(async ({ page }) => {
    const manifest = JSON.parse(
        await readFile('public/build/manifest.json', 'utf8'),
    );
    const entry = manifest['resources/js/app.ts'];
    const initialPage = {
        component: 'Ventes/Index',
        url: '/backoffice/precommandes',
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
                permissions: {
                    'ventes.read': true,
                    'ventes.precommander': true,
                },
            },
            theme: {
                active: { preset: 'aura', primary: 'blue', surface: 'slate' },
                allowed: {},
                locked: {},
            },
            commandes: [base, retrait],
            totaux: {
                total_montant: 0,
                total_a_encaisser: 0,
                deja_paye: 0,
                nb_total: 2,
                nb_cloturees: 0,
                montant_cloturees: 0,
            },
            indicateurs_precommandes: {
                en_cours: {
                    nombre: 2,
                    statuts: [
                        'reservee',
                        'a_preparer',
                        'preparee',
                        'a_charger',
                        'chargement_en_cours',
                        'livraison_en_cours',
                    ],
                },
                a_preparer: { nombre: 1, statuts: ['reservee', 'a_preparer'] },
                en_livraison: { nombre: 0, statuts: ['livraison_en_cours'] },
                en_retard: { nombre: 1, statuts: [] },
            },
            nature_filtree: 'vente_standard',
            liste: 'precommandes',
            page_title: 'Précommandes',
            can_precommander: true,
            can_creer_precommande: true,
            raison_blocage_precommande: null,
            periode: 'all',
            statuts_actifs: [],
            statuts: [],
            sites: [],
            vehicules: [],
            is_admin: false,
            can_creer_commande: true,
            raison_blocage_commande: null,
            filters: { site_ids: [], en_retard: null },
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
        if (pathname === '/backoffice/precommandes')
            return route.fulfill({
                contentType: 'text/html; charset=utf-8',
                body: html,
            });
        const file = path.resolve(publicRoot, `.${pathname}`);
        if (!file.startsWith(publicRoot + path.sep)) return route.abort();
        try {
            const contentType = file.endsWith('.js')
                ? 'text/javascript'
                : file.endsWith('.css')
                  ? 'text/css'
                  : 'application/octet-stream';
            await route.fulfill({ contentType, body: await readFile(file) });
        } catch {
            await route.fulfill({ status: 404, body: '' });
        }
    });
});

test('bureau : compteurs du cycle, véhicule, livreur et client joignables', async ({
    page,
}, testInfo) => {
    await page.setViewportSize({ width: 1600, height: 900 });
    await page.goto('http://ui-preview.test/backoffice/precommandes');

    for (const [cle, nombre] of [
        ['en_cours', '2'],
        ['a_preparer', '1'],
        ['en_livraison', '0'],
        ['en_retard', '1'],
    ]) {
        await expect(page.getByTestId(`indicateur-${cle}`)).toContainText(
            nombre,
        );
    }
    await expect(page.getByText('Restant à encaisser')).toHaveCount(0);
    // « Mes vues », comme sur le Stock (scope `precommandes`).
    await expect(page.getByRole('button', { name: /Mes vues/ })).toBeVisible();

    await expect(
        page.getByTestId('row-vehicule-immatriculation').first(),
    ).toHaveText('AI3462');
    await expect(
        page.getByTestId('row-livreur-telephone').first(),
    ).toContainText('613');
    await expect(page.getByTestId('row-client-telephone')).toHaveCount(2);
    await expect(page.getByTestId('row-date-prevue').nth(1)).toContainText(
        'En retard',
    );

    await page.screenshot({
        path: testInfo.outputPath('precommandes-bureau.png'),
        fullPage: true,
    });
});

test('mobile : compteurs et téléphones du livreur et du client dans la fiche', async ({
    page,
}, testInfo) => {
    await page.goto('http://ui-preview.test/backoffice/precommandes');

    await expect(page.getByText('En cours').first()).toBeVisible();
    await page
        .getByRole('button', { name: /VTE-051026-002/ })
        .first()
        .click();
    const drawer = page.getByRole('dialog');
    await expect(drawer).toContainText('Tél. chauffeur');
    await expect(drawer).toContainText('Tél. client');

    await page.screenshot({
        path: testInfo.outputPath('precommandes-mobile.png'),
        fullPage: true,
    });
});
