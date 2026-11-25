import DashboardDepenses from '@/pages/DashboardDepenses.vue';
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
const SelecteurAgenceStub = defineComponent({
    name: 'SelecteurAgence',
    props: ['url', 'agences', 'agence', 'parametres'],
    setup: () => () => h('div', { 'data-testid': 'selecteur-agence' }),
});
const SelecteurPeriodeStub = defineComponent({
    name: 'SelecteurPeriode',
    props: ['url', 'periode', 'parametres'],
    setup: () => () => h('div'),
});

const zero = { montant: 0, evolution: null };

function monter(props: Record<string, unknown> = {}) {
    return mount(DashboardDepenses, {
        props: {
            periode: {
                cle: 'ce_mois',
                date_debut: null,
                date_fin: null,
                options: [],
                libelle: 'Ce mois',
            },
            libelle_comparaison: 'Mois précédent',
            agences: [
                { value: 'm', label: 'Matoto' },
                { value: 'c', label: 'Cba' },
            ],
            agence: null,
            perimetre_limite: false,
            indicateurs: {
                total: zero,
                payees: zero,
                charges: zero,
                avances: zero,
            },
            en_attente: {
                montant: 0,
                nombre: 0,
                plus_ancienne: null,
                anciennete_jours: null,
            },
            rejetees: { montant: 0, nombre: 0, taux: null },
            evolution: { granularite: 'jour', tranches: [] },
            repartition_types: [],
            repartition_agences: [],
            ...props,
        },
        global: {
            stubs: {
                AppLayout: Passe,
                Head: Vide,
                InfoTooltip: Passe,
                SelecteurAgence: SelecteurAgenceStub,
                SelecteurPeriode: SelecteurPeriodeStub,
                DepensesStatsWidget: Vide,
                DepensesEvolutionWidget: Vide,
                DepensesTypesWidget: Vide,
                DepensesAgencesWidget: Vide,
            },
        },
    });
}

describe('DashboardDepenses', () => {
    it('propose le filtre agence et la période, qui conservent l’autre filtre', () => {
        const wrapper = monter({ agence: 'c' });

        const agence = wrapper.findComponent(SelecteurAgenceStub);
        expect(agence.exists()).toBe(true);
        // URL Wayfinder absolue : seul le chemin compte.
        expect(agence.props('url')).toContain(
            '/backoffice/tableau-de-bord/depenses',
        );
        expect(
            wrapper.findComponent(SelecteurPeriodeStub).props('parametres'),
        ).toEqual({ site_id: 'c' });
        expect(
            wrapper.find('[data-testid="dashboard-depenses-agences"]').text(),
        ).toBe('· Agence : Cba');
    });

    it('masque le filtre agence quand une seule agence est consultable', () => {
        const wrapper = monter({
            agences: [{ value: 'm', label: 'Matoto' }],
            perimetre_limite: true,
        });

        expect(wrapper.find('[data-testid="selecteur-agence"]').exists()).toBe(
            false,
        );
        expect(
            wrapper.find('[data-testid="dashboard-depenses-agences"]').text(),
        ).toBe('· Vos agences : Matoto');
        expect(
            wrapper
                .find('[data-testid="dashboard-depenses-comparaison"]')
                .text(),
        ).toContain('les dépenses que vous avez saisies');
    });
});
