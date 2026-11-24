import EvolutionCAWidget from '@/components/dashboard/ventes/EvolutionCAWidget.vue';
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
    props: ['type', 'data', 'options'],
    setup: (props) => () => h('canvas', { 'data-type': props.type }),
});

const tranches = [
    { cle: '2026-10', label: 'Oct', payees: 500, partielles: 20, impayees: 10 },
    { cle: '2026-11', label: 'Nov', payees: 300, partielles: 0, impayees: 40 },
];

describe('EvolutionCAWidget', () => {
    const monter = () =>
        mount(EvolutionCAWidget, {
            props: { evolution: { granularite: 'mois', tranches } },
            global: { stubs: { Chart: ChartStub } },
        });

    it('dessine les tranches du serveur telles quelles', () => {
        const data = monter().findComponent(ChartStub).props('data') as {
            labels: string[];
            datasets: { label: string; data: number[] }[];
        };

        expect(data.labels).toEqual(['Oct', 'Nov']);
        expect(data.datasets.map((d) => [d.label, d.data])).toEqual([
            ['Payées', [500, 300]],
            ['Partielles', [20, 0]],
            ['Impayées', [10, 40]],
        ]);
    });

    it('n’a pas de période propre : le filtre de la page est la seule source', () => {
        const wrapper = monter();

        expect(wrapper.find('select').exists()).toBe(false);
        expect(wrapper.findComponent({ name: 'Select' }).exists()).toBe(false);
    });
});
