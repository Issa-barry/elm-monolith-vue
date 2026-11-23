import {
    evolutionTexte,
    montantCompact,
    montantGnf,
    traceCourbe,
} from '@/components/dashboard/commissions/apollo';
import CommissionsEvolutionWidget from '@/components/dashboard/commissions/CommissionsEvolutionWidget.vue';
import CommissionsRepartitionWidget from '@/components/dashboard/commissions/CommissionsRepartitionWidget.vue';
import CommissionsStatsWidget from '@/components/dashboard/commissions/CommissionsStatsWidget.vue';
import type {
    IndicateursCommissions,
    TrancheCommissions,
} from '@/types/dashboard-commissions';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
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
    props: ['type', 'data', 'options', 'height'],
    setup: (props) => () => h('canvas', { 'data-type': props.type }),
});

const KnobStub = defineComponent({
    name: 'KnobStub',
    props: ['modelValue', 'valueTemplate', 'size', 'strokeWidth', 'readonly'],
    setup: (props) => () =>
        h('div', { 'data-knob': props.modelValue }, props.valueTemplate),
});

const SelectStub = defineComponent({
    name: 'SelectStub',
    props: ['modelValue', 'options', 'optionLabel', 'loading'],
    emits: ['update:modelValue', 'change'],
    setup: () => () => h('select'),
});

const stubs = { Chart: ChartStub, Knob: KnobStub, Select: SelectStub };

const indicateurs: IndicateursCommissions = {
    total_genere: { montant: 53_420_000, evolution: 12.5 },
    en_attente_periode: { montant: 1_200_000, evolution: -8 },
    payable: { montant: 450_000, evolution: null },
    deja_paye: { montant: 51_770_000, evolution: 0 },
};

const tranches: TrancheCommissions[] = [
    {
        cle: '2026-11-01',
        label: '01/11',
        total_genere: 100,
        en_attente_periode: 0,
        payable: 50,
        deja_paye: 50,
    },
    {
        cle: '2026-11-02',
        label: '02/11',
        total_genere: 300,
        en_attente_periode: 100,
        payable: 0,
        deja_paye: 200,
    },
];

const texte = (el: { text(): string }) => el.text().replace(/\s+/g, ' ');

describe('apollo.ts', () => {
    it('formate les montants en GNF, abrégés ou complets', () => {
        expect(montantGnf(53_420_000)).toBe('53 420 000 GNF');
        expect(montantCompact(53_420_000)).toBe('53,4 M');
        expect(montantCompact(1_250_000_000)).toBe('1,3 Md');
        expect(montantCompact(450_000)).toBe('450 k');
    });

    it('écrit les évolutions avec leur signe', () => {
        expect(evolutionTexte(12.5)).toBe('+12,5 %');
        expect(evolutionTexte(-8)).toBe('-8 %');
        expect(evolutionTexte(0)).toBe('0 %');
    });

    it('trace la courbe dans la viewBox, plate pour une série constante ou d’un point', () => {
        expect(traceCourbe([0, 10], 115, 41)).toBe(
            'M1 40C57.5 40 57.5 1 114 1',
        );
        expect(traceCourbe([5], 115, 41)).toBe('M1 40C57.5 40 57.5 40 114 40');
    });
});

describe('CommissionsStatsWidget', () => {
    const monter = (taux: number | null = 97) =>
        mount(CommissionsStatsWidget, {
            props: { indicateurs, tranches, tauxPaiement: taux },
            global: { stubs },
        });

    it('affiche les quatre cartes dans l’ordre du cycle de vie, montants en GNF', () => {
        const wrapper = monter();
        const cartes = wrapper.findAll('[data-testid^="kpi-"]');

        expect(cartes.map((c) => c.attributes('data-testid'))).toEqual([
            'kpi-total_genere',
            'kpi-en_attente_periode',
            'kpi-payable',
            'kpi-deja_paye',
        ]);
        expect(texte(cartes[0])).toContain('Commissions générées');
        const montant = cartes[0].find('[data-slot="montant"]');
        expect(montant.text()).toBe('53,4 M');
        expect(montant.attributes('title')).toBe('53 420 000 GNF');
        expect(texte(cartes[0])).toContain('GNF');
        expect(texte(cartes[3])).toContain('Payées');
    });

    it('montre la tendance réelle : vert en hausse, rose en baisse, neutre sans comparaison', () => {
        const wrapper = monter();
        const evolution = (id: string) =>
            wrapper.find(`[data-testid="kpi-${id}"] [data-slot="evolution"]`);

        expect(evolution('total_genere').text()).toBe('+12,5 %');
        expect(evolution('total_genere').classes()).toContain(
            'text-[var(--p-green-500)]',
        );
        expect(evolution('total_genere').find('i').classes()).toContain(
            'pi-arrow-up',
        );

        expect(evolution('en_attente_periode').text()).toBe('-8 %');
        expect(evolution('en_attente_periode').classes()).toContain(
            'text-[var(--p-pink-500)]',
        );
        expect(evolution('en_attente_periode').find('i').classes()).toContain(
            'pi-arrow-down',
        );

        // Aucune flèche ni pourcentage inventé sans période de comparaison.
        expect(evolution('payable').text()).toBe('—');
        expect(evolution('payable').find('i').exists()).toBe(false);
        expect(evolution('deja_paye').text()).toBe('0 %');
        expect(evolution('deja_paye').find('i').exists()).toBe(false);
    });

    it('trace les mini-courbes avec les montants de chaque tranche, rose en baisse', () => {
        const wrapper = monter();
        const chemin = (id: string) =>
            wrapper.find(`[data-testid="kpi-${id}"] path`);

        expect(
            wrapper
                .find('[data-testid="kpi-total_genere"] svg')
                .attributes('viewBox'),
        ).toBe('0 0 258 96');
        expect(chemin('total_genere').attributes('d')).toBe(
            traceCourbe([100, 300], 258, 96),
        );
        expect(chemin('en_attente_periode').attributes('style')).toContain(
            'var(--p-pink-500)',
        );
        expect(chemin('payable').attributes('style')).toContain(
            'var(--p-primary-color)',
        );
    });

    it('le cercle de la carte Payées donne le taux de paiement, vide sans commission', () => {
        expect(monter(97).find('[data-knob]').text()).toBe('97%');
        expect(monter(null).find('[data-knob]').text()).toBe('—');
    });
});

describe('CommissionsEvolutionWidget', () => {
    beforeEach(() => routerGet.mockClear());

    const periodes = [
        { value: 'ce_mois', label: 'Ce mois' },
        { value: 'cette_annee', label: 'Cette année' },
    ];

    it('dessine les commissions générées et payées par tranche', () => {
        const wrapper = mount(CommissionsEvolutionWidget, {
            props: { tranches, periode: 'ce_mois', periodes },
            global: { stubs },
        });

        expect(wrapper.text()).toContain('Évolution des commissions');
        const chart = wrapper.findComponent(ChartStub);
        expect(chart.props('type')).toBe('bar');
        expect(chart.props('height')).toBe(300);
        const data = chart.props('data') as {
            labels: string[];
            datasets: { label: string; data: number[] }[];
        };
        expect(data.labels).toEqual(['01/11', '02/11']);
        expect(data.datasets.map((d) => [d.label, d.data])).toEqual([
            ['Générées', [100, 300]],
            ['Payées', [50, 200]],
        ]);
    });

    it('recharge la page avec la période choisie', async () => {
        const wrapper = mount(CommissionsEvolutionWidget, {
            props: { tranches, periode: 'ce_mois', periodes },
            global: { stubs },
        });

        const select = wrapper.findComponent(SelectStub);
        await select.vm.$emit('update:modelValue', periodes[1]);
        await select.vm.$emit('change');

        expect(routerGet).toHaveBeenCalledWith(
            expect.stringMatching(
                /\/backoffice\/tableau-de-bord\/commissions$/,
            ),
            { periode: 'cette_annee' },
            expect.objectContaining({
                preserveState: true,
                preserveScroll: true,
            }),
        );
    });
});

describe('CommissionsRepartitionWidget', () => {
    it('répartit le total par type de bénéficiaire', () => {
        const wrapper = mount(CommissionsRepartitionWidget, {
            props: {
                repartition: [
                    { type: 'site', label: 'Sites', montant: 300 },
                    { type: 'livreur', label: 'Livreurs', montant: 150 },
                ],
            },
            global: { stubs },
        });

        const chart = wrapper.findComponent(ChartStub);
        expect(chart.props('type')).toBe('pie');
        const data = chart.props('data') as {
            labels: string[];
            datasets: { data: number[] }[];
        };
        expect(data.labels).toEqual(['Sites', 'Livreurs']);
        expect(data.datasets[0].data).toEqual([300, 150]);
    });

    it('affiche un état vide sans commission sur la période', () => {
        const wrapper = mount(CommissionsRepartitionWidget, {
            props: { repartition: [] },
            global: { stubs },
        });

        expect(wrapper.findComponent(ChartStub).exists()).toBe(false);
        expect(wrapper.find('[data-testid="repartition-vide"]').text()).toBe(
            'Aucune commission sur la période',
        );
    });
});
