import SelecteurArticle from '@/components/dashboard/stock/SelecteurArticle.vue';
import StockAlertesWidget from '@/components/dashboard/stock/StockAlertesWidget.vue';
import StockCartesWidget from '@/components/dashboard/stock/StockCartesWidget.vue';
import StockFluxWidget from '@/components/dashboard/stock/StockFluxWidget.vue';
import StockSitesWidget from '@/components/dashboard/stock/StockSitesWidget.vue';
import StockSyntheseWidget from '@/components/dashboard/stock/StockSyntheseWidget.vue';
import {
    quantiteCompacte,
    quantiteTexte,
} from '@/components/dashboard/stock/quantites';
import type { LigneAlerteStock } from '@/types/dashboard-stock';
import { flushPromises, mount } from '@vue/test-utils';
import PrimeVue from 'primevue/config';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

const { routerGet } = vi.hoisted(() => ({ routerGet: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router: { get: routerGet } }));

vi.mock('@/composables/useChartTheme', async () => {
    const { computed, ref } = await import('vue');

    return {
        useChartTheme: () => ({
            getPrimary: computed(() => 'emerald'),
            getSurface: computed(() => 'slate'),
            isDarkTheme: ref(false),
        }),
    };
});

const ChartStub = defineComponent({
    name: 'ChartStub',
    props: ['type', 'data', 'options', 'height', 'plugins'],
    setup: (props) => () => h('canvas', { 'data-type': props.type }),
});
const HistoriqueStub = defineComponent({
    name: 'HistoriqueModal',
    props: [
        'visible',
        'ajustements',
        'modifications',
        'motifOptions',
        'loading',
        'title',
    ],
    emits: ['update:visible', 'filter-motif'],
    setup: () => () => h('div'),
});

const texte = (s: string) => s.replace(/\s+/g, ' ').trim();

afterEach(() => {
    vi.restoreAllMocks();
    routerGet.mockReset();
});

describe('quantités', () => {
    it('formate des entiers français, valeurs absolues sur les axes', () => {
        expect(quantiteTexte(12500)).toBe('12 500');
        expect(quantiteTexte(-3)).toBe('-3');
        expect(quantiteCompacte(-12500)).toBe('12,5 k');
    });
});

describe('StockCartesWidget', () => {
    const monter = () =>
        mount(StockCartesWidget, {
            props: {
                etat: {
                    physique: 150,
                    reservee: 30,
                    disponible: 120,
                    en_transit: 40,
                    transferts_en_transit: 1,
                },
                alertes: { total: 3, ruptures: 1, faibles: 2, negatifs: 0 },
                flux: {
                    entrees: 55,
                    sorties: 36,
                    pertes_casses: 6,
                    ecarts_manquants: 3,
                    ecarts_surplus: 2,
                },
                article: 'Pack 1,5 L',
            },
        });

    it('affiche l’état actuel puis la période, rappelant l’article choisi', () => {
        const wrapper = monter();

        expect(
            wrapper
                .findAll('[data-testid^="kpi-"]')
                .map((c) => c.attributes('data-testid')),
        ).toEqual([
            'kpi-disponible',
            'kpi-reserve',
            'kpi-transit',
            'kpi-alertes',
            'kpi-entrees',
            'kpi-sorties',
            'kpi-pertes',
            'kpi-ecarts',
        ]);
        expect(
            texte(wrapper.find('[data-testid="stock-ligne-etat"]').text()),
        ).toBe('État actuel · Pack 1,5 L');
        const carte = (id: string) => wrapper.find(`[data-testid="kpi-${id}"]`);
        expect(carte('disponible').find('[data-slot="valeur"]').text()).toBe(
            '120',
        );
        expect(
            texte(carte('disponible').find('[data-slot="detail"]').text()),
        ).toBe('Physique 150 − réservé 30');
        expect(
            texte(carte('transit').find('[data-slot="detail"]').text()),
        ).toBe('1 transfert en cours');
        expect(
            texte(carte('alertes').find('[data-slot="detail"]').text()),
        ).toBe('1 rupture · 2 faibles · 0 négatif');
        expect(
            carte('alertes').find('[data-slot="detail"]').classes(),
        ).toContain('text-amber-600');
        expect(
            carte('reserve').find('[data-slot="detail"]').classes(),
        ).not.toContain('text-amber-600');
        expect(texte(carte('ecarts').find('[data-slot="detail"]').text())).toBe(
            'Manquants · surplus 2',
        );
    });
});

describe('StockFluxWidget', () => {
    it('empile les entrées au-dessus de zéro et les sorties en dessous, par motif', () => {
        const wrapper = mount(StockFluxWidget, {
            props: {
                article: 'Pack',
                evolution: {
                    granularite: 'jour',
                    tranches: [
                        { cle: '2026-11-01', label: '01/11' },
                        { cle: '2026-11-02', label: '02/11' },
                    ],
                    series: [
                        {
                            motif: 'apres_production',
                            label: 'Après production',
                            sens: 'entree',
                            valeurs: [50, 0],
                        },
                        {
                            motif: 'vente',
                            label: 'Vente',
                            sens: 'sortie',
                            valeurs: [0, -30],
                        },
                    ],
                },
            },
            global: { stubs: { Chart: ChartStub } },
        });

        const chart = wrapper.findComponent(ChartStub);
        const data = chart.props('data') as {
            labels: string[];
            datasets: { label: string; data: number[]; stack: string }[];
        };
        expect(data.labels).toEqual(['01/11', '02/11']);
        expect(data.datasets.map((d) => d.label)).toEqual([
            'Entrée · Après production',
            'Sortie · Vente',
        ]);
        expect(data.datasets[1].data).toEqual([0, -30]);
        const options = chart.props('options') as {
            scales: { y: { stacked: boolean } };
            plugins: {
                tooltip: {
                    callbacks: {
                        label: (c: {
                            dataset: { label: string };
                            parsed: { y: number };
                        }) => string;
                    };
                };
            };
        };
        expect(options.scales.y.stacked).toBe(true);
        // Infobulle en valeur absolue : une sortie de 30 reste « 30 ».
        expect(
            options.plugins.tooltip.callbacks.label({
                dataset: { label: 'Sortie · Vente' },
                parsed: { y: -30 },
            }),
        ).toBe('Sortie · Vente : 30');
    });

    it('affiche un état vide sans mouvement', () => {
        const wrapper = mount(StockFluxWidget, {
            props: {
                article: 'Pack',
                evolution: { granularite: 'jour', tranches: [], series: [] },
            },
            global: { stubs: { Chart: ChartStub } },
        });

        expect(wrapper.findComponent(ChartStub).exists()).toBe(false);
        expect(wrapper.find('[data-testid="flux-vide"]').exists()).toBe(true);
    });
});

describe('StockSitesWidget', () => {
    it('empile disponible et réservé par agence et signale une agence non disponible', () => {
        const wrapper = mount(StockSitesWidget, {
            props: {
                article: 'Pack',
                sites: [
                    {
                        id: 'm',
                        label: 'Matoto',
                        physique: 100,
                        reservee: 30,
                        disponible: 70,
                        disponible_sur_site: true,
                    },
                    {
                        id: 'c',
                        label: 'Cba',
                        physique: 5,
                        reservee: 0,
                        disponible: 5,
                        disponible_sur_site: false,
                    },
                ],
            },
            global: { stubs: { Chart: ChartStub } },
        });

        const chart = wrapper.findComponent(ChartStub);
        const data = chart.props('data') as {
            labels: string[];
            datasets: { data: number[] }[];
        };
        expect(data.labels).toEqual(['Matoto', 'Cba']);
        expect(data.datasets.map((d) => d.data)).toEqual([
            [70, 5],
            [30, 0],
        ]);
        const options = chart.props('options') as {
            indexAxis: string;
            plugins: {
                tooltip: {
                    callbacks: {
                        label: (c: unknown) => string;
                        footer: (e: { dataIndex: number }[]) => string;
                    };
                };
            };
        };
        expect(options.indexAxis).toBe('y');
        expect(
            options.plugins.tooltip.callbacks.label({
                dataIndex: 1,
                dataset: { label: 'Disponible' },
                parsed: { x: 5 },
            }),
        ).toBe('Disponible : 5 (article non disponible sur cette agence)');
        expect(
            options.plugins.tooltip.callbacks.footer([{ dataIndex: 0 }]),
        ).toBe('Physique : 100');
    });
});

describe('StockSyntheseWidget', () => {
    it('liste chaque article sans ligne de total', () => {
        const ligne = (id: string, label: string, physique: number) => ({
            id,
            label,
            physique,
            reservee: 0,
            disponible: physique,
            en_transit: 0,
            entrees: 0,
            sorties: 0,
            pertes_casses: 0,
            ecarts_manquants: 0,
        });
        const wrapper = mount(StockSyntheseWidget, {
            props: {
                article: 'a',
                lignes: [
                    ligne('a', 'Pack 350 ml', 1200),
                    ligne('b', 'Pack 1,5 L', 40),
                ],
            },
        });

        const lignes = wrapper.findAll(
            '[data-testid="stock-synthese"] tbody tr',
        );
        expect(lignes).toHaveLength(2);
        expect(lignes[0].findAll('td').map((td) => texte(td.text()))).toEqual([
            'Pack 350 ml',
            '1 200',
            '0',
            '1 200',
            '0',
            '0',
            '0',
            '0',
            '0',
        ]);
        expect(texte(wrapper.text())).not.toContain('Total');
    });
});

describe('StockAlertesWidget', () => {
    const ligne: LigneAlerteStock = {
        produit_id: 'p1',
        variante_id: 'v1',
        article: 'Pack 1,5 L',
        site_id: 's1',
        site: 'Matoto',
        physique: 5,
        reservee: 5,
        disponible: 0,
        seuil: 10,
        statut: 'rupture',
        statut_label: 'Rupture de stock',
        alerte_active: false,
    };

    it('ouvre l’historique existant du couple article × agence', async () => {
        const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(
                JSON.stringify({
                    ajustements: [{ id: 'm1' }],
                    modifications: [],
                    motifs_disponibles: [{ value: 'vente', label: 'Vente' }],
                }),
            ),
        );
        const wrapper = mount(StockAlertesWidget, {
            props: { lignes: [ligne] },
            global: {
                plugins: [PrimeVue],
                stubs: { HistoriqueModal: HistoriqueStub },
            },
        });

        expect(
            wrapper
                .findAll('[data-testid="stock-alertes"] tbody tr td')
                .map((td) => texte(td.text()))
                .slice(1, 7),
        ).toEqual(['Pack 1,5 L', 'Matoto', '0', '5 / 5', '10', 'Inactive']);
        await wrapper
            .find('[data-testid="alerte-historique"]')
            .trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            '/backoffice/produits/p1/historique?variante_id=v1&site_id=s1',
            expect.anything(),
        );
        const modale = wrapper.findComponent(HistoriqueStub);
        expect(modale.props('visible')).toBe(true);
        expect(modale.props('title')).toBe('Pack 1,5 L · Matoto');
        expect(modale.props('ajustements')).toEqual([{ id: 'm1' }]);

        // Filtre de motif : rechargé par le serveur.
        modale.vm.$emit('filter-motif', 'vente');
        await flushPromises();
        expect(fetchMock).toHaveBeenLastCalledWith(
            '/backoffice/produits/p1/historique?variante_id=v1&site_id=s1&motif=vente',
            expect.anything(),
        );
    });
});

describe('SelecteurArticle', () => {
    it('recharge la page avec l’article choisi en conservant les autres filtres', async () => {
        const wrapper = mount(SelecteurArticle, {
            props: {
                url: '/backoffice/tableau-de-bord/stock',
                articles: [
                    { value: 'a', label: 'Pack 350 ml', produit_id: 'p' },
                    { value: 'b', label: 'Pack 1,5 L', produit_id: 'p' },
                ],
                article: 'a',
                parametres: { periode: 'ce_mois', site_id: 's1' },
            },
            global: {
                stubs: {
                    Select: defineComponent({
                        name: 'ChoixStub',
                        props: ['modelValue', 'options'],
                        emits: ['update:modelValue'],
                        setup: () => () => h('div'),
                    }),
                },
            },
        });

        wrapper
            .findComponent({ name: 'ChoixStub' })
            .vm.$emit('update:modelValue', 'b');
        await flushPromises();

        expect(routerGet).toHaveBeenCalledWith(
            '/backoffice/tableau-de-bord/stock',
            { periode: 'ce_mois', site_id: 's1', article: 'b' },
            expect.objectContaining({ preserveState: true }),
        );
    });
});
