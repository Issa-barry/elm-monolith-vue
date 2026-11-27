import DashboardRh from '@/pages/DashboardRh.vue';
import type { AbsencesRh } from '@/types/dashboard-rh';
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
const CartesStub = stub('RhCartesWidget', [
    'effectifs',
    'sansDateEmbauche',
    'contrats',
    'finsCdd',
    'absences',
]);
const AgencesStub = stub('RhAgencesWidget', ['lignes']);
const DossiersStub = stub('RhDossiersWidget', ['dossiers']);
const MotifsStub = stub('RhMotifsWidget', ['lignes']);
const FinsStub = stub('RhFinsCddWidget', ['lignes']);

const ABSENCES: AbsencesRh = {
    jours: 0,
    absences: 0,
    salaries: 0,
    taux: null,
    taux_motif: 'aucun_salarie',
    jours_attendus: 0,
    salaries_taux: 0,
    salaries_exclus: 0,
    injustifiees_jours: 0,
    injustifiees_absences: 0,
    absents_aujourdhui: 0,
    a_venir: 0,
    jusqu_au: '2026-11-26',
    par_motif: [],
};

function monter(props: Record<string, unknown> = {}) {
    return mount(DashboardRh, {
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
            blocs: { effectifs: true, contrats: true, absences: true },
            effectifs: { actifs: 0, suspendus: 0, entrees: 0, sorties: 0 },
            effectifs_agences: [],
            a_completer: {
                presents: 0,
                sans_date_embauche: 3,
                sans_fonction: 0,
                sans_service: 0,
                sans_agence: 0,
                sortis_sans_date: 0,
            },
            contrats: { cdi: 0, cdd: 0, sans_contrat: 0 },
            fins_cdd: [],
            absences: ABSENCES,
            ...props,
        },
        global: {
            stubs: {
                InfoTooltip: Passe,
                SelecteurAgence: SelecteurAgenceStub,
                SelecteurPeriode: SelecteurPeriodeStub,
                RhCartesWidget: CartesStub,
                RhAgencesWidget: AgencesStub,
                RhDossiersWidget: DossiersStub,
                RhMotifsWidget: MotifsStub,
                RhFinsCddWidget: FinsStub,
            },
        },
    });
}

describe('DashboardRh', () => {
    it('relie les deux filtres en conservant toujours l’autre', () => {
        const wrapper = monter({ agence: 'c' });

        expect(
            wrapper.findComponent(SelecteurAgenceStub).props('parametres'),
        ).toEqual({ periode: 'ce_mois' });
        expect(
            wrapper.findComponent(SelecteurPeriodeStub).props('parametres'),
        ).toEqual({ site_id: 'c' });
        // URL Wayfinder absolue : seul le chemin compte.
        expect(
            wrapper.findComponent(SelecteurPeriodeStub).props('url'),
        ).toContain('/backoffice/tableau-de-bord/rh');
        expect(
            wrapper.find('[data-testid="dashboard-rh-agences"]').text(),
        ).toBe('· Agence : Cba');
        expect(
            wrapper.findComponent(CartesStub).props('sansDateEmbauche'),
        ).toBe(3);
    });

    it('affiche tous les blocs consultables', () => {
        const wrapper = monter();

        for (const nom of [
            'RhAgencesWidget',
            'RhDossiersWidget',
            'RhMotifsWidget',
            'RhFinsCddWidget',
        ]) {
            expect(wrapper.find(`[data-testid="${nom}"]`).exists(), nom).toBe(
                true,
            );
        }
    });

    it('n’affiche pas les blocs que le serveur n’envoie pas', () => {
        const wrapper = monter({
            blocs: { effectifs: false, contrats: false, absences: true },
            effectifs: null,
            effectifs_agences: null,
            a_completer: null,
            contrats: null,
            fins_cdd: null,
        });

        expect(wrapper.find('[data-testid="RhAgencesWidget"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-testid="RhDossiersWidget"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-testid="RhFinsCddWidget"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-testid="RhMotifsWidget"]').exists()).toBe(
            true,
        );
        expect(
            wrapper.findComponent(CartesStub).props('sansDateEmbauche'),
        ).toBeNull();
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
            wrapper.find('[data-testid="dashboard-rh-agences"]').text(),
        ).toBe('· Vos agences : Matoto');
        const aide = wrapper.find('[data-testid="dashboard-rh-aide"]').text();
        expect(aide).toContain('Effectifs et contrats sont ceux du jour');
        expect(aide).toContain('date d’embauche est connue');
        expect(aide).toContain('Périmètre des listes RH');
    });

    it('signale en clair le rattachement à l’agence actuelle du salarié', () => {
        const mention = monter()
            .find('[data-testid="dashboard-rh-agence-actuelle"]')
            .text();

        expect(mention).toContain('agence actuelle de chaque salarié');
        expect(mention).toContain('absences passées comprises');
    });
});
