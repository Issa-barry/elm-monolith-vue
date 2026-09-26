import DataFilters from '@/components/filters/DataFilters.vue';
import Activite from '@/pages/Rapports/Activite.vue';
import ListeFactures from '@/pages/Rapports/partials/ListeFactures.vue';
import type { RapportActivite } from '@/types/rapports';
import { router } from '@inertiajs/vue3';
import { mount, shallowMount } from '@vue/test-utils';
import Select from 'primevue/select';
import { beforeEach, describe, expect, it, vi } from 'vitest';

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
        router: { replace: vi.fn(), get: vi.fn() },
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
    ListPageActions: false,
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
                options: [
                    { value: 'aujourd_hui', label: "Aujourd'hui" },
                    { value: 'hier', label: 'Hier' },
                    { value: 'personnalisee', label: 'Période personnalisée' },
                ],
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
    beforeEach(() => {
        vi.clearAllMocks();
        url.valeur = '/backoffice/ma-situation?tab=caisse';
    });

    it('Ma situation affiche la période après PDF, sans drawer de filtres vide', () => {
        const wrapper = monter('ma_situation');
        expect(wrapper.findComponent(DataFilters).exists()).toBe(false);
        expect(wrapper.findComponent(Select).exists()).toBe(true);
        expect(
            wrapper
                .get('[data-testid="rapport-export-pdf"]')
                .element.nextElementSibling?.getAttribute('data-testid'),
        ).toBe('rapport-periode');
    });

    it('le drawer du rapport conserve Agent et Agence, sans la période', () => {
        const filtres = monter('rapport').findComponent(DataFilters);

        expect(
            filtres.props('fields').map((f: { key: string }) => f.key),
        ).toEqual(['agent_id']);
        expect(filtres.props('hideAgenceSelector')).toBe(false);
    });

    it('applique directement un raccourci en conservant agence, agent et onglet', async () => {
        url.valeur = '/backoffice/rapports/activite?tab=creances';
        const wrapper = monter('rapport');
        await wrapper.setProps({
            filters: {
                periode: 'personnalisee',
                date_from: '2026-09-01',
                date_to: '2026-09-25',
                site_ids: ['s1'],
                agent_id: 'u1',
            },
        });
        wrapper.findComponent(Select).vm.$emit('update:modelValue', 'hier');
        expect(router.get).toHaveBeenLastCalledWith(
            '/backoffice/rapports/activite',
            {
                tab: 'creances',
                periode: 'hier',
                site_ids: ['s1'],
                agent_id: 'u1',
            },
            expect.any(Object),
        );

        wrapper
            .findComponent(Select)
            .vm.$emit('update:modelValue', 'aujourd_hui');
        expect(router.get).toHaveBeenLastCalledWith(
            '/backoffice/rapports/activite',
            { tab: 'creances', site_ids: ['s1'], agent_id: 'u1' },
            expect.any(Object),
        );
    });

    it('applique une plage personnalisée complète sur validation, en rejetant les dates inversées', async () => {
        const wrapper = monter('ma_situation');
        wrapper
            .findComponent(Select)
            .vm.$emit('update:modelValue', 'personnalisee');
        await wrapper.vm.$nextTick();
        expect(router.get).not.toHaveBeenCalled();
        await wrapper
            .get('[aria-label="Date de début"]')
            .setValue('2026-09-27');
        await wrapper.get('[aria-label="Date de fin"]').setValue('2026-09-20');
        await wrapper.get('form').trigger('submit');
        expect(router.get).not.toHaveBeenCalled();
        await wrapper
            .get('[aria-label="Date de début"]')
            .setValue('2026-09-01');
        await wrapper.get('form').trigger('submit');
        expect(router.get).toHaveBeenCalledWith(
            '/backoffice/ma-situation',
            { tab: 'caisse', date_from: '2026-09-01', date_to: '2026-09-20' },
            expect.any(Object),
        );
    });

    it('conserve la période serveur dans le drawer, même pendant la saisie d’une autre plage', async () => {
        const wrapper = monter('rapport');
        await wrapper.setProps({
            filters: {
                periode: 'personnalisee',
                date_from: '2026-09-01',
                date_to: '2026-09-20',
                site_ids: [],
                agent_id: null,
            },
        });
        expect(
            wrapper.get('[aria-label="Date de début"]').element,
        ).toHaveProperty('value', '2026-09-01');
        expect(
            wrapper.get('[aria-label="Date de fin"]').element,
        ).toHaveProperty('value', '2026-09-20');
        await wrapper
            .get('[aria-label="Date de début"]')
            .setValue('2026-09-02');
        expect(wrapper.findComponent(DataFilters).props('baseParams')).toEqual({
            tab: 'caisse',
            date_from: '2026-09-01',
            date_to: '2026-09-20',
        });
    });

    it('regroupe les filtres dans le drawer et conserve la section lors du filtrage', async () => {
        url.valeur = '/backoffice/rapports/activite?tab=creances';
        const wrapper = monter('rapport');
        const filtres = wrapper.findComponent(DataFilters);
        expect(filtres.props('triggerOnly')).toBe(true);
        expect(filtres.props('baseParams')).toEqual({ tab: 'creances' });

        await wrapper
            .get('[data-testid="rapport-tab-encaissements"]')
            .trigger('click');
        expect(filtres.props('baseParams')).toEqual({ tab: 'encaissements' });
        expect(
            wrapper.get('[role="tabpanel"]').attributes('aria-labelledby'),
        ).toBe('rapport-tab-encaissements');
    });

    it('permet de parcourir les sections avec les flèches, Début et Fin', async () => {
        url.valeur = '/backoffice/rapports/activite?tab=ventes';
        const wrapper = monter('rapport');
        await wrapper
            .get('#rapport-tab-ventes')
            .trigger('keydown', { key: 'ArrowRight' });
        expect(
            wrapper
                .get('#rapport-tab-encaissements')
                .attributes('aria-selected'),
        ).toBe('true');
        expect(
            wrapper.get('#rapport-tab-encaissements').attributes('tabindex'),
        ).toBe('0');
        expect(wrapper.get('#rapport-tab-ventes').attributes('tabindex')).toBe(
            '-1',
        );
        await wrapper
            .get('#rapport-tab-encaissements')
            .trigger('keydown', { key: 'End' });
        expect(
            wrapper.get('#rapport-tab-caisse').attributes('aria-selected'),
        ).toBe('true');
        await wrapper
            .get('#rapport-tab-caisse')
            .trigger('keydown', { key: 'Home' });
        expect(
            wrapper.get('#rapport-tab-ventes').attributes('aria-selected'),
        ).toBe('true');
        await wrapper
            .get('#rapport-tab-ventes')
            .trigger('keydown', { key: 'ArrowLeft' });
        expect(
            wrapper.get('#rapport-tab-caisse').attributes('aria-selected'),
        ).toBe('true');
    });

    it('garde la portée toutes dates des dettes visible hors de l’aide détaillée', () => {
        url.valeur = '/backoffice/rapports/activite?tab=creances';
        const wrapper = monter('rapport');
        expect(wrapper.get('[role="tabpanel"]').text()).toContain(
            'Situation actuelle · Toutes dates',
        );
        expect(wrapper.get('details').text()).toContain(
            "la période choisie ne s'applique pas ici",
        );
        expect(wrapper.get('summary').attributes('aria-label')).toBe(
            'Comprendre : Dettes clients',
        );
    });

    it('sans caisse dédiée : un message, jamais un solde à zéro', () => {
        const wrapper = monter('ma_situation');

        expect(wrapper.get('[data-testid="kpi-caisse"]').text()).toBe(
            'Aucune caisse dédiée',
        );
        expect(wrapper.find('[data-testid="caisse-aucune"]').exists()).toBe(
            true,
        );
        expect(wrapper.get('#rapport-tab-ventes').attributes('tabindex')).toBe(
            '0',
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
