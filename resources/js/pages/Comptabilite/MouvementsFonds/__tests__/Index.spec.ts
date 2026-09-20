import StatusDot from '@/components/StatusDot.vue';
import DataFilters from '@/components/filters/DataFilters.vue';
import MouvementsIndex from '@/pages/Comptabilite/MouvementsFonds/Index.vue';
import { shallowMount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

vi.mock('@/composables/useFlashToast', () => ({ useFlashToast: vi.fn() }));
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
    const { defineComponent, h } = await import('vue');

    return {
        Head: defineComponent({ setup: () => () => null }),
        Link: defineComponent({
            setup:
                (_, { slots }) =>
                () =>
                    h('a', slots.default?.()),
        }),
        router: { post: vi.fn(), get: vi.fn() },
    };
});

const mouvement = (surcharge: Record<string, unknown> = {}) => ({
    id: 'm1',
    reference: 'MVT-2026-00001',
    nature: 'interne_caisses',
    nature_label: 'Versement de caisse',
    commentaire: null,
    site_origine: 'Matoto',
    site_destination: 'Matoto',
    site_destination_id: 's1',
    compte_origine: 'Caisse Saa Fodé',
    compte_destination: 'Caisse principale',
    compte_destination_id: 'c-agence',
    montant: 800_000,
    statut: 'envoye',
    statut_label: 'Envoyé',
    date_envoi: '2026-09-20',
    date_reception: null,
    expediteur: 'Issa BARRY',
    receptionnaire: null,
    created_at: '2026-09-20T10:00:00Z',
    peut_envoyer: false,
    peut_recevoir: false,
    peut_annuler: false,
    peut_contester: false,
    peut_confirmer_retour: false,
    ...surcharge,
});

const monter = (
    mouvements: ReturnType<typeof mouvement>[],
    surcharge: Record<string, unknown> = {},
) =>
    shallowMount(MouvementsIndex, {
        props: {
            mouvements: { data: mouvements, total: mouvements.length },
            filters: {
                statut: '',
                nature: '',
                search: '',
                site_ids: [],
                site_origine_id: '',
                site_destination_id: '',
                montant_min: '',
                montant_max: '',
            },
            statut_options: [],
            nature_options: [],
            sites: [],
            sites_mouvements: [],
            is_admin: true,
            peut_creer: false,
            comptes_tresorerie: [],
            ...surcharge,
        },
        global: {
            renderStubDefaultSlot: true,
            // Le stub par défaut ne rend que le slot par défaut : on veut aussi #filters et #primary.
            stubs: {
                ListPageActions: {
                    template:
                        '<div data-testid="list-page-actions"><slot name="filters" /><slot name="primary" /></div>',
                },
            },
        },
    });

const enAttente = (ligne: ReturnType<ReturnType<typeof monter>['find']>) =>
    ligne.find('[data-testid="mouvement-en-attente"]');

describe('Mouvements de fonds — versement en attente de confirmation', () => {
    it('reste identifiable caisse → caisse, avec son montant et son statut « Envoyé »', () => {
        const wrapper = monter([mouvement()]);
        const ligne = wrapper.find('tbody tr');

        expect(ligne.text()).toContain('Caisse Saa Fodé');
        expect(ligne.text()).toContain('Caisse principale');
        expect(ligne.text()).toContain('800 000 GNF');
        expect(ligne.findComponent(StatusDot).props('label')).toBe('Envoyé');
    });

    it('affiche « En attente de confirmation » sous le statut, à celui qui a envoyé comme à celui qui peut confirmer', () => {
        const [envoyeur, receveur] = monter([
            mouvement({ id: 'a', peut_recevoir: false }),
            mouvement({ id: 'b', peut_recevoir: true }),
        ]).findAll('tbody tr');

        expect(enAttente(envoyeur).text()).toBe('En attente de confirmation');
        expect(enAttente(receveur).text()).toBe('En attente de confirmation');
        expect(receveur.text()).toContain('Confirmer réception');
    });

    it("ne l'affiche plus une fois le versement reçu, ni pour un mouvement entre agences", () => {
        const [recu, entreAgences] = monter([
            mouvement({
                id: 'a',
                statut: 'recu',
                statut_label: 'Reçu',
                receptionnaire: 'Ibrahima Caissier',
                date_reception: '2026-09-21',
            }),
            mouvement({ id: 'b', nature: 'inter_sites' }),
        ]).findAll('tbody tr');

        expect(enAttente(recu).exists()).toBe(false);
        expect(enAttente(entreAgences).exists()).toBe(false);
    });
});

describe('Mouvements de fonds — filtres de la barre', () => {
    const sites = [
        { value: 's1', label: 'Matoto' },
        { value: 's2', label: 'Siège' },
    ];

    it('garde Référence, Nature, Origine et Destination directement dans la barre', () => {
        const champs = monter([], { sites_mouvements: sites })
            .findComponent(DataFilters)
            .props('fields');
        const parCle = Object.fromEntries(champs.map((c) => [c.key, c]));

        expect(parCle.search).toMatchObject({
            label: 'Référence',
            type: 'text',
            inline: true,
        });
        expect(parCle.nature).toMatchObject({
            label: 'Nature',
            type: 'select',
            inline: true,
        });
        expect(parCle.site_origine_id).toMatchObject({
            label: 'Origine',
            type: 'select',
            inline: true,
            options: sites,
        });
        expect(parCle.site_destination_id).toMatchObject({
            label: 'Destination',
            type: 'select',
            inline: true,
            options: sites,
        });
    });

    it('range Statut et Montant min/max dans le tiroir du bouton « Filtres » (pas dans la barre)', () => {
        const wrapper = monter([]);
        const filtres = wrapper.findComponent(DataFilters);
        const parCle = Object.fromEntries(
            filtres.props('fields').map((c) => [c.key, c]),
        );

        // Un champ sans `inline` va dans le tiroir ; DataFilters affiche alors le bouton « Filtres ».
        expect(parCle.statut.inline).toBeFalsy();
        expect(parCle.montant_min).toMatchObject({
            label: 'Montant min',
            type: 'number',
        });
        expect(parCle.montant_min.inline).toBeFalsy();
        expect(parCle.montant_max).toMatchObject({
            label: 'Montant max',
            type: 'number',
        });
        expect(parCle.montant_max.inline).toBeFalsy();
        // Le bouton « Filtres » vit dans la barre : la page n'utilise pas la variante « trigger-only ».
        expect(filtres.props('triggerOnly')).toBeFalsy();
    });

    it('place le bouton « Filtres » dans l’en-tête, juste avant « Nouveau mouvement »', async () => {
        const wrapper = monter([], { peut_creer: true });
        await nextTick();

        const cible = wrapper.findComponent(DataFilters).props('triggerTarget');
        const actions = wrapper.get('[data-testid="list-page-actions"]');
        const nouveau = actions.get('[href$="/mouvements/create"]');

        expect(cible).toBeInstanceOf(HTMLElement);
        expect(actions.element.contains(cible as HTMLElement)).toBe(true);
        expect(nouveau.text()).toBe('Nouveau mouvement');
        // Ordre standard des actions d'en-tête : Filtres puis Nouveau.
        expect(
            (cible as HTMLElement).compareDocumentPosition(nouveau.element) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
    });

    it('garde le bouton « Filtres » dans l’en-tête même sans le droit de créer un mouvement', async () => {
        const wrapper = monter([], { peut_creer: false });
        await nextTick();

        const cible = wrapper.findComponent(DataFilters).props('triggerTarget');
        const actions = wrapper.get('[data-testid="list-page-actions"]');

        expect(actions.element.contains(cible as HTMLElement)).toBe(true);
        expect(actions.find('[href$="/mouvements/create"]').exists()).toBe(
            false,
        );
    });

    it('conserve les filtres existants, dans le même ordre, avant les nouveaux', () => {
        const cles = monter([])
            .findComponent(DataFilters)
            .props('fields')
            .map((c) => c.key);

        expect(cles).toEqual([
            'search',
            'statut',
            'nature',
            'site_origine_id',
            'site_destination_id',
            'montant_min',
            'montant_max',
        ]);
    });

    it('restitue les valeurs filtrées à la barre pour qu’elles survivent au rechargement', () => {
        const filters = {
            statut: '',
            nature: '',
            search: '',
            site_ids: [],
            site_origine_id: 's2',
            site_destination_id: 's1',
            montant_min: '200000',
            montant_max: '900000',
        };

        expect(
            monter([], { filters }).findComponent(DataFilters).props('values'),
        ).toEqual(filters);
    });
});
