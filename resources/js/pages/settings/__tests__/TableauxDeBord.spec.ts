import TableauxDeBord from '@/pages/settings/TableauxDeBord.vue';
import { shallowMount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

/** Interrupteur PrimeVue remplacé : seules comptent ses props et son événement. */
const ToggleSwitch = defineComponent({
    name: 'ToggleSwitch',
    props: { modelValue: Boolean, disabled: Boolean },
    emits: ['update:modelValue'],
    setup: () => () => h('button'),
});

const ajouterToast = vi.fn();
vi.mock('primevue/usetoast', () => ({
    useToast: () => ({ add: ajouterToast }),
}));

type Options = {
    onSuccess?: () => void;
    onError?: () => void;
    onFinish?: () => void;
};
const patch =
    vi.fn<(url: string, donnees: unknown, options: Options) => void>();
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent } = await import('vue');

    return {
        Head: defineComponent({ setup: () => () => null }),
        router: {
            patch: (url: string, donnees: unknown, options: Options) =>
                patch(url, donnees, options),
        },
    };
});

const TABLEAUX = [
    {
        cle: 'tableau_de_bord.ventes',
        libelle: 'Stat-Ventes',
        description: 'Ventes',
        actif: true,
    },
    {
        cle: 'tableau_de_bord.vehicules',
        libelle: 'Stat-Véhicules',
        description: 'Parc',
        actif: false,
    },
];

const monter = () =>
    shallowMount(TableauxDeBord, {
        props: { tableaux: TABLEAUX },
        global: { renderStubDefaultSlot: true, stubs: { ToggleSwitch } },
    });

const interrupteur = (wrapper: ReturnType<typeof monter>, cle: string) =>
    wrapper.find(`[data-testid="tableau-${cle}"]`).findComponent(ToggleSwitch);

describe('Paramètres → Tableaux de bord', () => {
    beforeEach(() => {
        ajouterToast.mockClear();
        patch.mockClear();
    });

    it('liste chaque tableau avec son nom, sa description et son état', () => {
        const wrapper = monter();

        for (const tableau of TABLEAUX) {
            const ligne = wrapper.find(
                `[data-testid="tableau-${tableau.cle}"]`,
            );
            expect(ligne.text()).toContain(tableau.libelle);
            expect(ligne.text()).toContain(tableau.description);
            expect(interrupteur(wrapper, tableau.cle).props('modelValue')).toBe(
                tableau.actif,
            );
        }
    });

    it('enregistre le nouvel état côté serveur et confirme par un toast en haut à droite', async () => {
        const wrapper = monter();

        await interrupteur(wrapper, 'tableau_de_bord.vehicules').vm.$emit(
            'update:modelValue',
            true,
        );

        expect(patch).toHaveBeenCalledWith(
            '/settings/tableaux-de-bord',
            { tableau: 'tableau_de_bord.vehicules', actif: true },
            expect.objectContaining({ preserveScroll: true }),
        );
        patch.mock.calls[0][2].onSuccess?.();
        expect(ajouterToast).toHaveBeenCalledWith(
            expect.objectContaining({
                group: 'top',
                severity: 'success',
                detail: 'Stat-Véhicules activé.',
            }),
        );
    });

    it('verrouille l’interrupteur pendant la requête et refuse un second envoi', async () => {
        const wrapper = monter();
        const toggle = () => interrupteur(wrapper, 'tableau_de_bord.ventes');

        await toggle().vm.$emit('update:modelValue', false);
        await toggle().vm.$emit('update:modelValue', false);

        expect(patch).toHaveBeenCalledTimes(1);
        expect(toggle().props('disabled')).toBe(true);

        patch.mock.calls[0][2].onFinish?.();
        await wrapper.vm.$nextTick();
        expect(toggle().props('disabled')).toBe(false);
    });

    it('signale un refus du serveur par un toast d’erreur', async () => {
        const wrapper = monter();

        await interrupteur(wrapper, 'tableau_de_bord.ventes').vm.$emit(
            'update:modelValue',
            false,
        );
        patch.mock.calls[0][2].onError?.();

        expect(ajouterToast).toHaveBeenCalledWith(
            expect.objectContaining({ group: 'top', severity: 'error' }),
        );
    });
});
