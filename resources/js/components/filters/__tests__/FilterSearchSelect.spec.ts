import FilterSearchSelect from '@/components/filters/FilterSearchSelect.vue';
import { shallowMount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent } from 'vue';

const options = [
    { value: 'c1', label: 'Caisse Moussa sidibé' },
    { value: 'c2', label: 'Caisse Agence' },
];

// Le Select PrimeVue hérite de ses props (`extends`) : son stub automatique n'en expose aucune. On le
// remplace par un composant qui déclare celles qui nous intéressent.
const SelectFactice = defineComponent({
    props: {
        modelValue: { type: null, default: null },
        options: { type: Array, default: () => [] },
        optionLabel: { type: String, default: '' },
        optionValue: { type: String, default: '' },
        placeholder: { type: String, default: '' },
        disabled: Boolean,
        filter: Boolean,
        showClear: Boolean,
        autoFilterFocus: Boolean,
    },
    emits: ['update:modelValue'],
    template: '<div />',
});

const monter = (modelValue: (string | number)[] = [], props = {}) => {
    const wrapper = shallowMount(FilterSearchSelect, {
        props: { options, modelValue, ...props },
        global: { stubs: { Select: SelectFactice } },
    });

    return { wrapper, select: wrapper.findComponent(SelectFactice) };
};

describe('FilterSearchSelect', () => {
    it('affiche une liste avec recherche par nom et croix d’effacement, alimentée par les options', () => {
        const { select } = monter();

        expect(select.props()).toMatchObject({
            filter: true,
            autoFilterFocus: true,
            showClear: true,
            optionLabel: 'label',
            optionValue: 'value',
            options,
        });
    });

    it('affiche la valeur choisie (modèle à une valeur) ou rien quand il est vide', () => {
        expect(monter(['c1']).select.props('modelValue')).toBe('c1');
        expect(monter([]).select.props('modelValue')).toBeNull();
    });

    it('émet un tableau à une valeur au choix, comme FilterMultiSelect', () => {
        const { wrapper, select } = monter();

        select.vm.$emit('update:modelValue', 'c2');

        expect(wrapper.emitted('update:modelValue')).toEqual([[['c2']]]);
    });

    it('émet un tableau vide quand la croix efface le choix', () => {
        const { wrapper, select } = monter(['c1']);

        select.vm.$emit('update:modelValue', null);

        expect(wrapper.emitted('update:modelValue')).toEqual([[[]]]);
    });

    it('transmet le texte d’invite et la désactivation', () => {
        const { select } = monter([], {
            placeholder: 'Rechercher une caisse…',
            disabled: true,
        });

        expect(select.props('placeholder')).toBe('Rechercher une caisse…');
        expect(select.props('disabled')).toBe(true);
    });

    it('donne le nom complet du choix en infobulle, et rien sans choix', () => {
        expect(monter(['c1']).select.attributes('title')).toBe(
            'Caisse Moussa sidibé',
        );
        expect(monter([]).select.attributes('title')).toBeUndefined();
    });
});
