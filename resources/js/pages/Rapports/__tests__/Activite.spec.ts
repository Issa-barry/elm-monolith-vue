import DataFilters from '@/components/filters/DataFilters.vue';
import Activite from '@/pages/Rapports/Activite.vue';
import type { RapportActivite } from '@/types/rapports';
import { shallowMount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const url = vi.hoisted(() => ({
    valeur: '/backoffice/ma-situation?tab=caisse',
}));

vi.mock('@/layouts/AppLayout.vue', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        default: defineComponent({
            setup:
                (_, { slots }) =>
                () =>
                    h('div', slots.default?.()),
        }),
    };
});
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent } = await import('vue');

    return {
        Head: defineComponent({ setup: () => () => null }),
        router: { replace: vi.fn() },
        usePage: () => ({
            url: url.valeur,
            component: 'Rapports/Activite',
            props: {},
        }),
    };
});

const rapportVide = (): RapportActivite => ({
    ventes: {
        resume: {
            nombre: 0,
            facture: 0,
            encaisse: 0,
            reste: 0,
            annulees_nombre: 0,
            annulees_montant: 0,
        },
        lignes: [],
        total_lignes: 0,
    },
    encaissements: {
        resume: { nombre: 0, montant: 0 },
        par_moyen: [],
        lignes: [],
        total_lignes: 0,
    },
    creances: {
        resume: {
            nombre: 0,
            impayees: 0,
            partielles: 0,
            facture: 0,
            reste: 0,
            plus_ancienne: null,
        },
        lignes: [],
        total_lignes: 0,
    },
    mobile_money: {
        resume: {
            nombre: 0,
            montant: 0,
            reference_absente: 0,
            reference_dupliquee: 0,
            anterieure_obligation: 0,
        },
        par_operateur: [],
        lignes: [],
        total_lignes: 0,
    },
    caisse: {
        aucune_caisse: true,
        detail: true,
        resume: {
            solde_debut: 0,
            entrees: 0,
            sorties: 0,
            solde_fin: 0,
            solde_actuel: 0,
            en_cours_montant: 0,
            en_cours_nombre: 0,
            contestes_nombre: 0,
        },
        fiches: [],
    },
});

const monter = (mode: 'ma_situation' | 'rapport') =>
    shallowMount(Activite, {
        props: {
            mode,
            url:
                mode === 'ma_situation'
                    ? '/backoffice/ma-situation'
                    : '/backoffice/rapports/activite',
            export_url: '/backoffice/ma-situation/export',
            filters: {
                periode: 'aujourd_hui',
                date_from: null,
                date_to: null,
                site_ids: [],
                agent_id: null,
            },
            periode: {
                cle: 'aujourd_hui',
                date_debut: '2026-09-26',
                date_fin: '2026-09-26',
                libelle: "Aujourd'hui (26/09/2026)",
                options: [{ value: 'aujourd_hui', label: "Aujourd'hui" }],
            },
            sites: [],
            agents: [{ value: 'u1', label: 'Moussa Sidibé' }],
            agent:
                mode === 'ma_situation'
                    ? { id: 'u1', nom: 'Moussa Sidibé' }
                    : null,
            limite_lignes: 300,
            rapport: rapportVide(),
        },
        global: { renderStubDefaultSlot: true },
    });

describe('Rapports/Activite', () => {
    it("Ma situation ne propose ni filtre Agent ni sélecteur d'agence", () => {
        const filtres = monter('ma_situation').findComponent(DataFilters);

        expect(
            filtres.props('fields').map((f: { key: string }) => f.key),
        ).toEqual(['periode']);
        expect(filtres.props('hideAgenceSelector')).toBe(true);
    });

    it('le rapport propose Agent puis Période, après le filtre Agence', () => {
        const filtres = monter('rapport').findComponent(DataFilters);

        expect(
            filtres.props('fields').map((f: { key: string }) => f.key),
        ).toEqual(['agent_id', 'periode']);
        expect(filtres.props('hideAgenceSelector')).toBe(false);
    });

    it('sans caisse dédiée : un message, jamais un solde à zéro', () => {
        const wrapper = monter('ma_situation');

        expect(wrapper.get('[data-testid="kpi-caisse"]').text()).toBe(
            'Aucune caisse dédiée',
        );
        expect(wrapper.find('[data-testid="caisse-aucune"]').exists()).toBe(
            true,
        );
    });

    it("les exports reprennent les filtres de l'écran, sans l'onglet", () => {
        url.valeur = '/backoffice/ma-situation?periode=hier&tab=caisse';
        const wrapper = monter('ma_situation');

        expect(
            wrapper
                .get('[data-testid="rapport-export-pdf"]')
                .attributes('href'),
        ).toBe('/backoffice/ma-situation/export?periode=hier&format=pdf');
    });
});
