import DashboardStock from '@/pages/DashboardStock.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    router: { get: vi.fn() },
}));
vi.mock('@/layouts/AppLayout.vue', () => ({
    default: { template: '<div><slot /></div>' },
}));

const Passe = defineComponent({
    setup:
        (_, { slots }) =>
        () =>
            h('div', slots.default?.()),
});
const Vide = defineComponent({ setup: () => () => h('div') });
const stub = <P extends string>(name: string, props: P[]) =>
    defineComponent({
        name,
        props,
        setup: () => () => h('div', { 'data-testid': name }),
    });
const SelecteurAgenceStub = stub('SelecteurAgence', [
    'url',
    'agences',
    'agence',
    'parametres',
]);
const SelecteurArticleStub = stub('SelecteurArticle', [
    'url',
    'articles',
    'article',
    'parametres',
]);
const SelecteurPeriodeStub = stub('SelecteurPeriode', [
    'url',
    'periode',
    'parametres',
]);
const CartesStub = stub('StockCartesWidget', [
    'etat',
    'alertes',
    'flux',
    'article',
]);

function monter(props: Record<string, unknown> = {}) {
    return mount(DashboardStock, {
        props: {
            periode: {
                cle: 'ce_mois',
                date_debut: null,
                date_fin: null,
                options: [],
                libelle: 'Ce mois',
            },
            agences: [
                { value: 'm', label: 'Matoto' },
                { value: 'c', label: 'Cba' },
            ],
            agence: null,
            perimetre_limite: false,
            articles: [
                { value: 'a', label: 'Pack 350 ml', produit_id: 'p' },
                { value: 'b', label: 'Pack 1,5 L', produit_id: 'q' },
            ],
            article: 'b',
            etat: {
                physique: 0,
                reservee: 0,
                disponible: 0,
                en_transit: 0,
                transferts_en_transit: 0,
            },
            alertes: { total: 0, ruptures: 0, faibles: 0, negatifs: 0 },
            flux_periode: {
                entrees: 0,
                sorties: 0,
                pertes_casses: 0,
                ecarts_manquants: 0,
                ecarts_surplus: 0,
            },
            evolution: { granularite: 'jour', tranches: [], series: [] },
            sites: [],
            synthese: [],
            alertes_lignes: [],
            ...props,
        },
        global: {
            stubs: {
                InfoTooltip: Passe,
                SelecteurAgence: SelecteurAgenceStub,
                SelecteurArticle: SelecteurArticleStub,
                SelecteurPeriode: SelecteurPeriodeStub,
                StockCartesWidget: CartesStub,
                StockFluxWidget: Vide,
                StockSitesWidget: Vide,
                StockSyntheseWidget: Vide,
                StockAlertesWidget: Vide,
            },
        },
    });
}

describe('DashboardStock', () => {
    it('relie les trois filtres en conservant toujours les deux autres', () => {
        const wrapper = monter({ agence: 'c' });

        expect(
            wrapper.findComponent(SelecteurAgenceStub).props('parametres'),
        ).toEqual({ periode: 'ce_mois', article: 'b' });
        expect(
            wrapper.findComponent(SelecteurArticleStub).props('parametres'),
        ).toEqual({ periode: 'ce_mois', site_id: 'c' });
        expect(
            wrapper.findComponent(SelecteurPeriodeStub).props('parametres'),
        ).toEqual({ site_id: 'c', article: 'b' });
        // URL Wayfinder absolue : seul le chemin compte.
        expect(
            wrapper.findComponent(SelecteurArticleStub).props('url'),
        ).toContain('/backoffice/tableau-de-bord/stock');
        expect(
            wrapper.find('[data-testid="dashboard-stock-agences"]').text(),
        ).toBe('· Agence : Cba');
        // Les cartes reçoivent le libellé de l'article choisi, jamais un total d'articles.
        expect(wrapper.findComponent(CartesStub).props('article')).toBe(
            'Pack 1,5 L',
        );
    });

    it('masque le filtre agence avec une seule agence et explique la lecture', () => {
        const wrapper = monter({
            agences: [{ value: 'm', label: 'Matoto' }],
            perimetre_limite: true,
        });

        expect(wrapper.find('[data-testid="SelecteurAgence"]').exists()).toBe(
            false,
        );
        expect(
            wrapper.find('[data-testid="dashboard-stock-agences"]').text(),
        ).toBe('· Vos agences : Matoto');
        const aide = wrapper
            .find('[data-testid="dashboard-stock-aide"]')
            .text();
        expect(aide).toContain('jamais additionnées entre articles');
        expect(aide).toContain('Périmètre de la page Stock');
    });
});
