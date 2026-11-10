import { expect, test, type Page } from '@playwright/test';
import { readFile } from 'node:fs/promises';
import path from 'node:path';

// Vrai bundle avec données fictives : aucune modification en base.
const commande = {
    id: 'vente-1',
    reference: 'VTE-051126-001',
    statut: 'livraison_en_cours',
    statut_label: 'Livraison en cours',
    statut_affichage: { value: 'livree', label: 'Livrée' },
    statut_color: 'blue',
    total_commande: 10800000,
    mode_tarification_snapshot: 'prix_vente',
    mode_tarification_label: 'Prix de vente',
    mode_remise_grossiste: null,
    mode_remise_grossiste_label: null,
    est_precommande: false,
    date_remise_prevue: null,
    en_retard: false,
    vehicule_nom: 'KAWOU',
    vehicule_detail: {
        nom: 'KAWOU',
        immatriculation: 'AL9842',
        type: 'Camion',
        capacites: [{ categorie_nom: 'Eau', capacite_max: 1200 }],
        proprietaire_nom: 'Kawou Hassane',
        proprietaire_telephone: '+224629454591',
        proprietaire_code_phone_pays: '+224',
    },
    livreur_nom: 'Doumbouya Mamadou',
    livreur_telephone: '+224624206168',
    equipe_detail: {
        nom: 'KAWOU',
        taux_commission_proprietaire: 20,
        chauffeur: { nom: 'Doumbouya Mamadou', telephone: '+224624206168' },
        convoyeurs: [],
    },
    client_nom: null,
    client_detail: null,
    site_nom: 'Matoto',
    motif_annulation: null,
    annulee_at: null,
    a_charger_at: '05/11/2026',
    chargement_demarre_at: '05/11/2026',
    chargement_valide_at: '05/11/2026',
    livree_at: null,
    reception_validee_at: null,
    closed_at: null,
    is_brouillon: false,
    is_a_charger: false,
    is_chargement_en_cours: false,
    is_livraison_en_cours: true,
    is_livree: false,
    is_facturation: false,
    is_cloturee: false,
    is_annulee: false,
    is_retournee: false,
    is_annulee_erreur_saisie: false,
    annulation_exceptionnelle: null,
    can_modifier: false,
    can_confirmer: false,
    can_demarrer_chargement: false,
    can_valider_chargement: false,
    can_valider_reception: false,
    can_enregistrer_retour: true,
    can_annuler: false,
    can_annuler_exceptionnel: false,
    can_regulariser: false,
    can_encaisser: true,
    encaissement_est_acompte: false,
    peut_encaisser_especes: true,
    moyens_encaissement: [],
    encaissement_agences: null,
    created_at: '05/11/2026',
    created_by: 'Aperçu UI',
    lignes: [
        {
            id: 'ligne-1',
            produit_id: 'produit-1',
            produit_nom: 'Pack eau minérale 1,5 L × 6',
            quantite_demandee: 1200,
            quantite_chargee: 1180,
            quantite_livree: null,
            quantite_retournee: 0,
            quantite_retournable: 1180,
            type_ecart: 'casse',
            type_ecart_label: 'Casse au chargement',
            commentaire_ecart: 'Palette abîmée',
            type_ecart_reception: null,
            type_ecart_reception_label: null,
            commentaire_ecart_reception: null,
            ecart_chargement: -20,
            ecart_livraison: null,
            prix_usine_snapshot: 8000,
            prix_vente_snapshot: 9000,
            prix_origine_snapshot: 'vente',
            total_ligne: 10620000,
        },
        {
            id: 'ligne-2',
            produit_id: 'produit-2',
            produit_nom: 'Sachet 50 cl',
            quantite_demandee: 20,
            quantite_chargee: 20,
            quantite_livree: null,
            quantite_retournee: 0,
            quantite_retournable: 20,
            type_ecart: null,
            type_ecart_label: null,
            commentaire_ecart: null,
            type_ecart_reception: null,
            type_ecart_reception_label: null,
            commentaire_ecart_reception: null,
            ecart_chargement: 0,
            ecart_livraison: null,
            prix_usine_snapshot: 8000,
            prix_vente_snapshot: 9000,
            prix_origine_snapshot: 'vente',
            total_ligne: 180000,
        },
    ],
};
const facture = {
    id: 1,
    reference: 'FAC-051126-001',
    montant_net: 10800000,
    montant_encaisse: 4000000,
    montant_restant: 6800000,
    statut: 'partiel',
    statut_label: 'Partiellement payée',
    encaissements: [
        {
            id: 1,
            montant: 4000000,
            date_encaissement: '05/11/2026',
            heure: '09:40',
            mode_paiement: 'mobile_money',
            mode_paiement_label: 'Mobile Money',
            operateur_mobile_money_label: 'Orange Money',
            reference_paiement: 'OM-REF-00012345678',
            est_acompte: false,
            note: null,
            created_by: 'Aperçu UI',
            encaisse_a: null,
            pour_autre_agence: false,
            reversement: null,
        },
    ],
};

async function ouvrir(page: Page, options: { dark?: boolean } = {}) {
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
            permissions: { 'ventes.read': true },
        },
        theme: {
            active: { preset: 'aura', primary: 'blue', surface: 'slate' },
            allowed: {},
            locked: {},
        },
        commande,
        facture,
        commission_statut: { value: 'impaye', label: 'Impayée' },
        commission_generation_statut: null,
        historiques: [
            {
                id: 1,
                event_code: 'updated',
                event_label: 'Modifiée',
                actor_name: 'Aperçu UI',
                old_values: { statut: 'chargement_en_cours' },
                new_values: { statut: 'livraison_en_cours' },
                created_at: '05/11/2026 09:30',
            },
        ],
        activites: [
            {
                id: 'act-1',
                action: 'chargement_valide',
                action_label: 'a validé le chargement',
                user_name: 'Aperçu UI',
                created_at: '05/11/2026 09:30',
                details: null,
            },
        ],
        retours: [],
        motifs_retour: [],
        precommande: null,
        regularisations: [],
        regularisation_remboursement: null,
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
        if (url.pathname.startsWith('/backoffice/ventes')) {
            if (request.method() !== 'GET')
                mutations.push(request.method() + ' ' + url.pathname);
            const data = {
                component: 'Ventes/Show',
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
    await page.goto('http://ui-preview.test/backoffice/ventes/vente-1');
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
            .locator('.vente-detail')
            .evaluate((el) => el.scrollWidth <= el.clientWidth),
    ).toBe(true);
}

/** L'élément est entièrement visible dans la zone défilante de son conteneur. */
async function visibleDans(conteneur: string, cible: string, page: Page) {
    return page.locator(conteneur).evaluate((el, sel) => {
        const c = el.getBoundingClientRect();
        const t = el.querySelector(sel)!.getBoundingClientRect();
        return t.left >= c.left - 1 && t.right <= c.right + 1;
    }, cible);
}

for (const width of [360, 390, 440]) {
    test(
        'mobile ' + width + ' : fiche vente sans débordement',
        async ({ page }, info) => {
            const errors: string[] = [];
            page.on('pageerror', (error) => errors.push(error.message));
            await page.setViewportSize({ width, height: 900 });
            const { mutations } = await ouvrir(page);

            // Frise : défile dans sa carte, étape courante visible.
            const stepper = page.getByTestId('vente-stepper');
            expect(
                await stepper.evaluate((el) => el.scrollWidth > el.clientWidth),
            ).toBe(true);
            expect(
                await visibleDans(
                    '[data-testid="vente-stepper"]',
                    '[data-step-current]',
                    page,
                ),
            ).toBe(true);

            // Onglets sur une seule ligne, défilants.
            const tabs = page.getByRole('tab');
            const hauteurs = await tabs.evaluateAll((els) =>
                els.map((el) => el.getBoundingClientRect().height),
            );
            expect(new Set(hauteurs).size).toBe(1);
            expect(hauteurs[0]).toBeGreaterThanOrEqual(44);

            // Informations : statuts qui passent à la ligne, grille en lignes.
            await expect(page.getByTestId('vente-statuts')).toContainText(
                'Commission : Impayée',
            );
            await sansDebordement(page);
            await page.screenshot({
                path: info.outputPath('informations.png'),
                fullPage: true,
            });

            for (const nom of [
                'Produits',
                'Facturation',
                "Journal d'activité",
                'Historique',
            ]) {
                await page.getByRole('tab', { name: nom, exact: true }).click();
                await expect(
                    page.getByRole('tab', { name: nom, exact: true }),
                ).toHaveAttribute('aria-selected', 'true');
                expect(
                    await visibleDans(
                        '[data-testid="vente-tabs"]',
                        '[aria-selected="true"]',
                        page,
                    ),
                ).toBe(true);
                await sansDebordement(page);
                await page.screenshot({
                    path: info.outputPath(nom + '.png'),
                    fullPage: true,
                });
            }
            expect(mutations).toEqual([]);
            expect(errors).toEqual([]);
        },
    );
}

test('produits et encaissements : cartes avec libellés sur mobile', async ({
    page,
}) => {
    await page.setViewportSize({ width: 390, height: 900 });
    await ouvrir(page);
    await page.getByRole('tab', { name: 'Produits', exact: true }).click();
    const lignes = page.locator('[data-mobile-cards="lignes"]');
    await expect(lignes.locator('thead')).toBeHidden();
    expect(
        await lignes
            .locator('tbody tr')
            .first()
            .evaluate((el) => getComputedStyle(el).display),
    ).toBe('grid');
    await expect(lignes).toContainText('Casse au chargement');
    await expect(lignes.locator('tfoot')).toContainText('10 800 000');

    await page.getByRole('tab', { name: 'Facturation', exact: true }).click();
    const kpis = page.getByTestId('facturation-kpis');
    expect(
        await kpis.evaluate((el) => getComputedStyle(el).gridTemplateColumns),
    ).not.toContain(' ');
    const encaissements = page.locator('[data-mobile-cards="encaissements"]');
    await expect(encaissements).toContainText('OM-REF-00012345678');
    await expect(encaissements).toContainText('Orange Money');
    const encaisser = page.getByRole('button', { name: /Encaisser/ });
    expect((await encaisser.boundingBox())!.height).toBeGreaterThanOrEqual(44);
    await sansDebordement(page);
});

test('mobile sombre : aucun débordement', async ({ page }, info) => {
    await page.setViewportSize({ width: 360, height: 802 });
    await ouvrir(page, { dark: true });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await sansDebordement(page);
    await page.screenshot({ path: info.outputPath('sombre.png') });
});

test('bureau 1440 : frise, onglets et tableaux conservés', async ({
    page,
}, info) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await ouvrir(page);
    const stepper = page.getByTestId('vente-stepper');
    expect(
        await stepper.evaluate((el) => el.scrollWidth <= el.clientWidth),
    ).toBe(true);
    await page.screenshot({ path: info.outputPath('bureau.png') });
    await page.getByRole('tab', { name: 'Produits', exact: true }).click();
    await expect(
        page.locator('[data-mobile-cards="lignes"] thead'),
    ).toBeVisible();
    await page.getByRole('tab', { name: 'Facturation', exact: true }).click();
    await expect(
        page.locator('[data-mobile-cards="encaissements"] thead'),
    ).toBeVisible();
    await sansDebordement(page);
    await page.screenshot({ path: info.outputPath('bureau-facturation.png') });
});
