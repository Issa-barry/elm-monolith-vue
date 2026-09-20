import StatusDot from '@/components/StatusDot.vue';
import MouvementsIndex from '@/pages/Comptabilite/MouvementsFonds/Index.vue';
import { shallowMount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

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

const monter = (mouvements: ReturnType<typeof mouvement>[]) =>
    shallowMount(MouvementsIndex, {
        props: {
            mouvements: { data: mouvements, total: mouvements.length },
            filters: { statut: '', nature: '', search: '', site_ids: [] },
            statut_options: [],
            nature_options: [],
            sites: [],
            is_admin: true,
            peut_creer: false,
            comptes_tresorerie: [],
        },
        global: { renderStubDefaultSlot: true },
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
