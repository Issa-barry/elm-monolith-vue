import SituationTab from '@/pages/Vehicules/partials/SituationTab.vue';
import SituationVentesSection from '@/pages/Vehicules/partials/situation/SituationVentesSection.vue';
import type {
    SituationPeriode,
    SituationVentesData,
} from '@/types/vehicule-situation';
import { mount } from '@vue/test-utils';
import Select from 'primevue/select';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

const etat = vi.hoisted(() => ({
    url: '',
    get: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        get url() {
            return etat.url;
        },
    }),
    router: { get: etat.get },
}));

// PrimeVue exige son plugin : le sélecteur est remplacé par un composant qui déclare les mêmes props.
vi.mock('primevue/select', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        default: defineComponent({
            props: {
                modelValue: { type: String, default: '' },
                options: { type: Array, default: () => [] },
                optionLabel: { type: String, default: '' },
                optionValue: { type: String, default: '' },
                inputId: { type: String, default: '' },
            },
            emits: ['update:modelValue'],
            setup: () => () => h('div', { 'data-testid': 'selecteur' }),
        }),
    };
});

const OPTIONS: SituationPeriode['options'] = [
    { value: 'tout', label: 'Toute la période' },
    { value: 'aujourd_hui', label: "Aujourd'hui" },
    { value: 'hier', label: 'Hier' },
    { value: 'ce_mois', label: 'Ce mois' },
    { value: 'personnalisee', label: 'Période personnalisée' },
];

const ventes: SituationVentesData = {
    kpis: { ca_vendu: 0, encaisse: 0, reste_du: 0, nb_ventes: 0 },
    produits: [],
    paiements: { total_montant: 0, total_ventes: 0, repartition: [] },
};

const TOUTE_LA_PERIODE: SituationPeriode = {
    cle: 'tout',
    date_debut: null,
    date_fin: null,
    options: OPTIONS,
};

const CE_MOIS: SituationPeriode = {
    cle: 'ce_mois',
    date_debut: '2026-09-01',
    date_fin: '2026-09-30',
    options: OPTIONS,
};

const PERSONNALISEE: SituationPeriode = {
    cle: 'personnalisee',
    date_debut: '2026-09-01',
    date_fin: '2026-09-13',
    options: OPTIONS,
};

const OPTIONS_VISITE = {
    preserveScroll: true,
    preserveState: true,
    replace: true,
};

const monter = (periode: SituationPeriode = TOUTE_LA_PERIODE) =>
    mount(SituationTab, {
        props: {
            vehiculeId: 'V1',
            vehiculeRecherche: 'AI3462',
            periode,
            ventes,
        },
        global: { stubs: { SituationVentesSection: true } },
    });

const choisir = (wrapper: ReturnType<typeof monter>, valeur: string) =>
    wrapper.findComponent(Select).vm.$emit('update:modelValue', valeur);

const champ = (wrapper: ReturnType<typeof monter>, id: string) =>
    wrapper.find(`#situation-date-${id}`);

describe('SituationTab — période', () => {
    beforeEach(() => {
        etat.url = '/backoffice/vehicules/V1?tab=situation';
        etat.get.mockReset();
    });

    it('propose les périodes rapides du serveur et affiche la période active', () => {
        const wrapper = monter(CE_MOIS);
        const selecteur = wrapper.findComponent(Select);

        expect(selecteur.props('modelValue')).toBe('ce_mois');
        expect(selecteur.props('options')).toEqual(OPTIONS);
        expect(wrapper.find('#situation-date-debut').exists()).toBe(false);
    });

    it('transmet une seule période à toute la Situation', () => {
        const wrapper = monter(CE_MOIS);

        expect(
            wrapper.findComponent(SituationVentesSection).props('periode'),
        ).toEqual(CE_MOIS);
    });

    it("applique une période rapide en gardant l'onglet Situation dans l'URL", () => {
        choisir(monter(), 'ce_mois');

        expect(etat.get).toHaveBeenCalledWith(
            '/backoffice/vehicules/V1',
            { tab: 'situation', situation_periode: 'ce_mois' },
            OPTIONS_VISITE,
        );
    });

    it('remplace une période personnalisée par une période rapide, sans toucher aux autres paramètres', () => {
        etat.url =
            '/backoffice/vehicules/V1?tab=situation&date_from=2026-09-01&date_to=2026-09-13&processus=vente';

        choisir(monter(PERSONNALISEE), 'hier');

        expect(etat.get.mock.calls[0][1]).toEqual({
            tab: 'situation',
            processus: 'vente',
            situation_periode: 'hier',
        });
    });

    it("« Toute la période » n'envoie aucun paramètre de période", () => {
        etat.url =
            '/backoffice/vehicules/V1?tab=situation&situation_periode=ce_mois';

        choisir(monter(CE_MOIS), 'tout');

        expect(etat.get.mock.calls[0][1]).toEqual({ tab: 'situation' });
    });

    it('ne recharge rien quand la période choisie est déjà active', () => {
        choisir(monter(CE_MOIS), 'ce_mois');

        expect(etat.get).not.toHaveBeenCalled();
    });

    it('affiche les deux dates pour une période personnalisée, sans appel serveur', async () => {
        const wrapper = monter();

        choisir(wrapper, 'personnalisee');
        await nextTick();

        expect(champ(wrapper, 'debut').exists()).toBe(true);
        expect(champ(wrapper, 'fin').exists()).toBe(true);
        expect(etat.get).not.toHaveBeenCalled();
    });

    it('restaure les dates de la période personnalisée du serveur', () => {
        const wrapper = monter(PERSONNALISEE);

        expect(
            (champ(wrapper, 'debut').element as HTMLInputElement).value,
        ).toBe('2026-09-01');
        expect((champ(wrapper, 'fin').element as HTMLInputElement).value).toBe(
            '2026-09-13',
        );
    });

    it('préremplit les dates avec les bornes de la période active', async () => {
        const wrapper = monter(CE_MOIS);

        choisir(wrapper, 'personnalisee');
        await nextTick();

        expect(
            (champ(wrapper, 'debut').element as HTMLInputElement).value,
        ).toBe('2026-09-01');
        expect((champ(wrapper, 'fin').element as HTMLInputElement).value).toBe(
            '2026-09-30',
        );
        expect(etat.get).not.toHaveBeenCalled();
    });

    it("applique la période personnalisée dès que les deux dates sont valides, avec l'onglet Situation", async () => {
        const wrapper = monter();
        choisir(wrapper, 'personnalisee');
        await nextTick();

        await champ(wrapper, 'debut').setValue('2026-09-01');
        expect(etat.get).not.toHaveBeenCalled();

        await champ(wrapper, 'fin').setValue('2026-09-13');

        expect(etat.get).toHaveBeenCalledTimes(1);
        expect(etat.get).toHaveBeenCalledWith(
            '/backoffice/vehicules/V1',
            {
                tab: 'situation',
                date_from: '2026-09-01',
                date_to: '2026-09-13',
            },
            OPTIONS_VISITE,
        );
        expect(
            wrapper.find('[data-testid="situation-periode-erreur"]').exists(),
        ).toBe(false);
    });

    it('modifier une date recharge avec la nouvelle période et le même onglet', async () => {
        etat.url =
            '/backoffice/vehicules/V1?tab=situation&date_from=2026-09-01&date_to=2026-09-13';
        const wrapper = monter(PERSONNALISEE);

        await champ(wrapper, 'debut').setValue('2026-09-05');

        expect(etat.get.mock.calls[0][1]).toEqual({
            tab: 'situation',
            date_from: '2026-09-05',
            date_to: '2026-09-13',
        });
    });

    it.each([
        ['debut', '2026-09-05', 'Renseignez la date de fin.'],
        ['fin', '2026-09-13', 'Renseignez la date de début.'],
    ])(
        'exige les deux dates : %s seule → message, aucun appel',
        async (quel, valeur, message) => {
            const wrapper = monter();
            choisir(wrapper, 'personnalisee');
            await nextTick();

            await champ(wrapper, quel).setValue(valeur);

            expect(
                wrapper.find('[data-testid="situation-periode-erreur"]').text(),
            ).toBe(message);
            expect(etat.get).not.toHaveBeenCalled();
        },
    );

    it('refuse une date de début postérieure à la date de fin', async () => {
        const wrapper = monter();
        choisir(wrapper, 'personnalisee');
        await nextTick();

        await champ(wrapper, 'debut').setValue('2026-09-13');
        await champ(wrapper, 'fin').setValue('2026-09-01');

        expect(
            wrapper.find('[data-testid="situation-periode-erreur"]').text(),
        ).toBe('La date de début doit précéder la date de fin.');
        expect(etat.get).not.toHaveBeenCalled();
    });

    it('reprend la période renvoyée par le serveur après chaque rechargement', async () => {
        const wrapper = monter();

        await wrapper.setProps({ periode: CE_MOIS });

        expect(wrapper.findComponent(Select).props('modelValue')).toBe(
            'ce_mois',
        );
    });
});
