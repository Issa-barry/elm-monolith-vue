import DepensesAgencesWidget from '@/components/dashboard/depenses/DepensesAgencesWidget.vue';
import DepensesEvolutionWidget from '@/components/dashboard/depenses/DepensesEvolutionWidget.vue';
import DepensesStatsWidget from '@/components/dashboard/depenses/DepensesStatsWidget.vue';
import DepensesTypesWidget from '@/components/dashboard/depenses/DepensesTypesWidget.vue';
import type {
    IndicateursDepenses,
    TrancheDepenses,
} from '@/types/dashboard-depenses';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

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

const KnobStub = defineComponent({
    name: 'KnobStub',
    props: ['modelValue', 'valueTemplate', 'size', 'strokeWidth', 'readonly'],
    setup: (props) => () =>
        h('div', { 'data-knob': props.modelValue }, props.valueTemplate),
});

const stubs = { Chart: ChartStub, Knob: KnobStub };

const indicateurs: IndicateursDepenses = {
    total: { montant: 215_000, evolution: 12.5 },
    payees: { montant: 190_000, evolution: -8 },
    charges: { montant: 140_000, evolution: 0 },
    avances: { montant: 50_000, evolution: null },
};

const tranches: TrancheDepenses[] = [
    {
        cle: '2026-11-01',
        label: '01/11',
        total: 100,
        payees: 80,
        charges: 50,
        avances: 30,
    },
    {
        cle: '2026-11-02',
        label: '02/11',
        total: 200,
        payees: 150,
        charges: 100,
        avances: 50,
    },
];

const texte = (s: string) => s.replace(/\s/g, ' ');

describe('DepensesStatsWidget', () => {
    const monter = (
        surcharges: Partial<{
            enAttente: Record<string, unknown>;
            rejetees: Record<string, unknown>;
        }> = {},
    ) =>
        mount(DepensesStatsWidget, {
            props: {
                indicateurs,
                tranches,
                enAttente: {
                    montant: 25_000,
                    nombre: 2,
                    plus_ancienne: '2026-11-03',
                    anciennete_jours: 19,
                    ...surcharges.enAttente,
                },
                rejetees: {
                    montant: 15_000,
                    nombre: 1,
                    taux: 14.3,
                    ...surcharges.rejetees,
                },
            },
            global: { stubs },
        });

    it('affiche les six cartes dans l’ordre des deux lignes', () => {
        const wrapper = monter();

        const ids = wrapper
            .findAll('[data-testid^="kpi-"]')
            .map((c) => c.attributes('data-testid'));
        expect(ids).toEqual([
            'kpi-total',
            'kpi-payees',
            'kpi-en_attente',
            'kpi-charges',
            'kpi-avances',
            'kpi-rejetees',
        ]);
        expect(
            wrapper
                .find('[data-testid="kpi-total"] [data-slot="montant"]')
                .text(),
        ).toBe('215 k');
    });

    it('reprend les couleurs d’Apollo : hausse en vert, baisse en rose', () => {
        const wrapper = monter();
        const evolution = (id: string) =>
            wrapper.find(`[data-testid="kpi-${id}"] [data-slot="evolution"]`);

        expect(evolution('total').classes()).toContain(
            'text-[var(--p-green-500)]',
        );
        expect(texte(evolution('total').text())).toBe('+12,5 %');
        expect(evolution('payees').classes()).toContain(
            'text-[var(--p-pink-500)]',
        );
        expect(evolution('charges').classes()).toContain(
            'text-[var(--p-text-muted-color)]',
        );
        expect(evolution('avances').text()).toBe('—');
    });

    it('donne le nombre et l’ancienneté des dépenses en attente', () => {
        const wrapper = monter();
        const carte = wrapper.find('[data-testid="kpi-en_attente"]');

        expect(carte.find('[data-slot="nombre"]').text()).toBe('2 dépenses');
        expect(carte.find('[data-slot="anciennete"]').text()).toContain('19 j');
        expect(carte.find('[data-slot="anciennete"]').text()).toContain(
            'dépense du 03/11/2026',
        );
    });

    it('donne le taux de rejet dans le cercle, « — » sans dépense tranchée', () => {
        const avecTaux = monter().findComponent(KnobStub);
        expect(avecTaux.props('modelValue')).toBe(14);
        expect(avecTaux.props('valueTemplate')).toBe('14,3 %');

        const sansTaux = monter({
            rejetees: { montant: 0, nombre: 0, taux: null },
            enAttente: {
                montant: 0,
                nombre: 0,
                plus_ancienne: null,
                anciennete_jours: null,
            },
        });
        expect(sansTaux.findComponent(KnobStub).props('valueTemplate')).toBe(
            '—',
        );
        expect(
            sansTaux
                .find('[data-testid="kpi-en_attente"] [data-slot="anciennete"]')
                .text(),
        ).toBe('—');
    });
});

describe('DepensesEvolutionWidget', () => {
    it('empile les charges et les avances de chaque tranche', () => {
        const wrapper = mount(DepensesEvolutionWidget, {
            props: { tranches },
            global: { stubs },
        });

        const chart = wrapper.findComponent(ChartStub);
        const data = chart.props('data') as {
            labels: string[];
            datasets: { label: string; data: number[] }[];
        };
        expect(data.labels).toEqual(['01/11', '02/11']);
        expect(data.datasets.map((d) => d.label)).toEqual([
            'Charges entreprise',
            'Avances récupérables',
        ]);
        expect(data.datasets[0].data).toEqual([50, 100]);
        expect(data.datasets[1].data).toEqual([30, 50]);
        const options = chart.props('options') as {
            scales: { x: { stacked: boolean }; y: { stacked: boolean } };
        };
        expect(options.scales.x.stacked).toBe(true);
        expect(options.scales.y.stacked).toBe(true);
    });
});

describe('DepensesTypesWidget', () => {
    it('donne le pourcentage de chaque type dans la légende et l’infobulle', () => {
        const wrapper = mount(DepensesTypesWidget, {
            props: {
                repartition: [
                    { id: 'a', label: 'Carburant', montant: 300 },
                    { id: null, label: 'Autres types', montant: 100 },
                ],
            },
            global: { stubs },
        });

        const chart = wrapper.findComponent(ChartStub);
        expect((chart.props('data') as { labels: string[] }).labels).toEqual([
            'Carburant',
            'Autres types',
        ]);
        // Légende HTML sous le camembert : jamais coupée sur téléphone.
        expect(
            wrapper
                .findAll('[data-testid="types-legende"] li')
                .map((li) => texte(li.text())),
        ).toEqual(['Carburant (75,0 %)', 'Autres types (25,0 %)']);
        const options = chart.props('options') as {
            plugins: {
                tooltip: {
                    callbacks: {
                        label: (c: {
                            dataIndex: number;
                            parsed: number;
                        }) => string;
                    };
                };
            };
        };
        expect(
            texte(
                options.plugins.tooltip.callbacks.label({
                    dataIndex: 0,
                    parsed: 300,
                }),
            ),
        ).toBe('Carburant : 300 GNF (75,0 %)');
    });

    it('affiche un état vide sans dépense payée', () => {
        const wrapper = mount(DepensesTypesWidget, {
            props: { repartition: [] },
            global: { stubs },
        });

        expect(wrapper.findComponent(ChartStub).exists()).toBe(false);
        expect(wrapper.find('[data-testid="types-vide"]').exists()).toBe(true);
    });
});

describe('DepensesAgencesWidget', () => {
    it('compare les agences en barres horizontales avec montant et pourcentage', () => {
        const wrapper = mount(DepensesAgencesWidget, {
            props: {
                agences: [
                    { id: 'm', label: 'Matoto', montant: 150_000 },
                    { id: 'c', label: 'Cba', montant: 50_000 },
                ],
            },
            global: { stubs },
        });

        const chart = wrapper.findComponent(ChartStub);
        expect(
            (chart.props('options') as { indexAxis: string }).indexAxis,
        ).toBe('y');
        expect((chart.props('data') as { labels: string[] }).labels).toEqual([
            'Matoto',
            'Cba',
        ]);

        const [plugin] = chart.props('plugins') as {
            afterDatasetsDraw: (chart: unknown) => void;
        }[];
        const fillText = vi.fn();
        plugin.afterDatasetsDraw({
            ctx: { save: vi.fn(), restore: vi.fn(), fillText },
            getDatasetMeta: () => ({
                data: [
                    { x: 100, y: 10 },
                    { x: 40, y: 30 },
                ],
            }),
        });
        expect(texte(fillText.mock.calls[0][0])).toBe('150 k · 75,0 %');
        expect(fillText.mock.calls[0].slice(1)).toEqual([108, 10]);
        expect(texte(fillText.mock.calls[1][0])).toBe('50 k · 25,0 %');
    });
});
