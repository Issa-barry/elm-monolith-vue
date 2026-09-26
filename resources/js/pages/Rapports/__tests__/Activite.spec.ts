import DataFilters from '@/components/filters/DataFilters.vue';
import Activite from '@/pages/Rapports/Activite.vue';
import ListeFactures from '@/pages/Rapports/partials/ListeFactures.vue';
import type { RapportActivite } from '@/types/rapports';
import { mount, shallowMount } from '@vue/test-utils';
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
            props: { auth: { user_sites: [{ id: 's1', nom: 'Matoto' }] } },
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

// Composants propres à la page rendus pour de vrai (cartes, sections, listes) ; seuls
// DataFilters et AppLayout restent des bouchons.
const composantsReels = {
    CarteSection: false,
    EnTeteSection: false,
    ListeFactures: false,
    ListeEncaissements: false,
    SectionCaisse: false,
};

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
        global: { renderStubDefaultSlot: true, stubs: composantsReels },
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

    it('parle de « Dettes clients », jamais de « créances », dans l’interface', () => {
        url.valeur = '/backoffice/ma-situation?tab=creances';
        const wrapper = monter('ma_situation');

        expect(
            wrapper.get('[data-testid="rapport-tab-creances"]').text(),
        ).toContain('Dettes clients');
        expect(wrapper.text()).toContain('Aucune dette client en cours.');
        expect(wrapper.text()).not.toMatch(/créance/i);

        url.valeur = '/backoffice/ma-situation?tab=ventes';
        expect(monter('rapport').text()).not.toMatch(/créance/i);
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

    it('en-tête : identité et agence pour Ma situation, périmètre en clair pour le rapport', () => {
        url.valeur = '/backoffice/ma-situation';
        expect(
            monter('ma_situation')
                .get('[data-testid="rapport-perimetre"]')
                .text(),
        ).toBe('Moussa Sidibé · Matoto');

        url.valeur = '/backoffice/rapports/activite';
        expect(
            monter('rapport').get('[data-testid="rapport-perimetre"]').text(),
        ).toBe('Toutes les agences · Tous les agents');
    });

    it('les cartes sont les onglets : une seule carte active, reconnaissable sans la couleur', async () => {
        url.valeur = '/backoffice/rapports/activite?tab=creances';
        const wrapper = monter('rapport');
        const cartes = wrapper.findAll('[role="tab"]');

        expect(cartes.map((c) => c.attributes('data-testid'))).toEqual([
            'rapport-tab-ventes',
            'rapport-tab-encaissements',
            'rapport-tab-creances',
            'rapport-tab-mobile_money',
            'rapport-tab-caisse',
        ]);
        const actives = cartes.filter(
            (c) => c.attributes('aria-selected') === 'true',
        );
        expect(actives).toHaveLength(1);
        expect(actives[0].text()).toContain('(détail affiché)');
        expect(wrapper.text()).not.toContain('Encaissé sur ces ventes');
    });

    it('sur téléphone, les lignes sont une liste empilée et le tableau est réservé aux écrans larges', () => {
        const wrapper = mount(ListeFactures, {
            props: {
                afficherAgent: false,
                vide: 'Aucune vente.',
                lignes: [
                    {
                        id: 'f1',
                        reference: 'FAC-001',
                        date: '2026-09-26',
                        client: 'Client A',
                        agent: null,
                        site_nom: 'Matoto',
                        montant: 800_000,
                        encaisse: 550_000,
                        reste: 250_000,
                        statut: 'partiel',
                        statut_label: 'Partiellement payée',
                    },
                ],
            },
        });

        const liste = wrapper.get('[data-testid="liste-mobile"]');
        expect(liste.classes()).toContain('sm:hidden');
        expect(liste.text()).toContain('FAC-001');
        expect(liste.text()).toContain('Reste à payer');
        expect(wrapper.get('table').element.parentElement?.className).toContain(
            'hidden',
        );
    });
});
