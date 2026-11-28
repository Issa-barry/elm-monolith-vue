import DashboardVehicules from '@/pages/DashboardVehicules.vue';
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
const SelecteurPeriodeStub = stub('SelecteurPeriode', [
    'url',
    'periode',
    'parametres',
]);
const CartesStub = stub('VehiculesCartesWidget', [
    'blocs',
    'parc',
    'operations',
    'activite',
    'depenses',
]);
const OperationsStub = stub('VehiculesOperationsWidget', [
    'operations',
    'blocs',
]);
const RotationsStub = stub('VehiculesRotationsWidget', ['evolution']);
const CategoriesStub = stub('VehiculesCategoriesWidget', ['lignes']);
const ClassementStub = stub('VehiculesClassementWidget', [
    'lignes',
    'activite',
    'depenses',
]);
const AConfigurerStub = stub('VehiculesAConfigurerWidget', ['lignes']);

function monter(props: Record<string, unknown> = {}) {
    return mount(DashboardVehicules, {
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
            blocs: { ventes: true, transferts: true, depenses: true },
            parc: {
                total: 0,
                actifs: 0,
                inactifs: 0,
                operationnels: 0,
                a_configurer: 0,
                en_mission: null,
            },
            operations: [],
            activite: {
                rotations: 0,
                vehicules_utilises: 0,
                actifs: 0,
                actifs_utilises: 0,
                taux_utilisation: null,
                jours_vehicule: 0,
                avec_retour: 0,
                taux_retour: null,
                transferts_avec_ecart: 0,
            },
            evolution: { granularite: 'jour', tranches: [], series: [] },
            categories: [],
            depenses: {
                montant: 0,
                nombre: 0,
                vehicules: 0,
                rotations: 0,
                cout_par_rotation: null,
            },
            vehicules: [],
            a_configurer: [],
            ...props,
        },
        global: {
            stubs: {
                InfoTooltip: Passe,
                SelecteurAgence: SelecteurAgenceStub,
                SelecteurPeriode: SelecteurPeriodeStub,
                VehiculesCartesWidget: CartesStub,
                VehiculesOperationsWidget: OperationsStub,
                VehiculesRotationsWidget: RotationsStub,
                VehiculesCategoriesWidget: CategoriesStub,
                VehiculesClassementWidget: ClassementStub,
                VehiculesAConfigurerWidget: AConfigurerStub,
            },
        },
    });
}

describe('DashboardVehicules', () => {
    it('relie les deux filtres en conservant toujours l’autre', () => {
        const wrapper = monter({ agence: 'c' });

        expect(
            wrapper.findComponent(SelecteurAgenceStub).props('parametres'),
        ).toEqual({ periode: 'ce_mois' });
        expect(
            wrapper.findComponent(SelecteurPeriodeStub).props('parametres'),
        ).toEqual({ site_id: 'c' });
        expect(
            wrapper.findComponent(SelecteurPeriodeStub).props('url'),
        ).toContain('/backoffice/tableau-de-bord/vehicules');
        expect(
            wrapper.find('[data-testid="dashboard-vehicules-agences"]').text(),
        ).toBe('· Agence : Cba');
    });

    it('affiche tous les blocs avec toutes les sources', () => {
        const wrapper = monter();

        for (const nom of [
            'VehiculesCartesWidget',
            'VehiculesOperationsWidget',
            'VehiculesRotationsWidget',
            'VehiculesCategoriesWidget',
            'VehiculesClassementWidget',
            'VehiculesAConfigurerWidget',
        ]) {
            expect(wrapper.find(`[data-testid="${nom}"]`).exists(), nom).toBe(
                true,
            );
        }
        expect(
            wrapper
                .find('[data-testid="dashboard-vehicules-sources-absentes"]')
                .exists(),
        ).toBe(false);
        expect(wrapper.findComponent(ClassementStub).props()).toMatchObject({
            activite: true,
            depenses: true,
        });
    });

    it('sans accès à l’activité ni aux dépenses : pas de graphique, et le dit', () => {
        const wrapper = monter({
            blocs: { ventes: false, transferts: false, depenses: false },
            activite: null,
            evolution: null,
            depenses: null,
        });

        expect(
            wrapper.find('[data-testid="VehiculesRotationsWidget"]').exists(),
        ).toBe(false);
        expect(
            wrapper.find('[data-testid="VehiculesOperationsWidget"]').exists(),
        ).toBe(true);
        expect(wrapper.findComponent(ClassementStub).props()).toMatchObject({
            activite: false,
            depenses: false,
        });
        expect(
            wrapper
                .find('[data-testid="dashboard-vehicules-sources-absentes"]')
                .text(),
        ).toBe('Non affiché faute d’accès : ventes, transferts, dépenses.');
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
            wrapper.find('[data-testid="dashboard-vehicules-agences"]').text(),
        ).toBe('· Vos agences : Matoto');
        const aide = wrapper
            .find('[data-testid="dashboard-vehicules-aide"]')
            .text();
        expect(aide).toContain('L’état du parc est celui du jour');
        expect(aide).toContain('Aucune donnée de panne');
        expect(aide).toContain('vos agences de rattachement');
        expect(
            wrapper
                .find('[data-testid="dashboard-vehicules-agence-rattachement"]')
                .text(),
        ).toContain('transfert : agence de départ');
    });
});
