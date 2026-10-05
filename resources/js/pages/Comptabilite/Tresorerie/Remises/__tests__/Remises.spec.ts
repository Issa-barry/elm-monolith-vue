import StatusDot from '@/components/StatusDot.vue';
import RemisesIndex from '@/pages/Comptabilite/Tresorerie/Remises/Index.vue';
import RemisesShow from '@/pages/Comptabilite/Tresorerie/Remises/Show.vue';
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
        router: { get: vi.fn(), post: vi.fn() },
    };
});

const LIGNE = {
    site_id: 's-kankan',
    site_nom: 'Kankan',
    attendu: 1_500_000,
    deja_remis: 0,
    en_transit: 1_000_000,
    reste_a_recevoir: 500_000,
    remise_obligatoire: 500_000,
    excedent_a_remettre: 0,
    derniere_remise: '2026-10-03',
    statut: 'remise_en_cours',
    statut_label: 'Remise en cours',
};

describe('Remises des agences — vue du Trésor principal', () => {
    it('met le reste à recevoir au centre et affiche attendu, en transit et statut', () => {
        const wrapper = shallowMount(RemisesIndex, {
            props: {
                central: { id: 's-matoto', nom: 'Matoto' },
                lignes: [LIGNE],
                totaux: {
                    attendu: 1_500_000,
                    deja_remis: 0,
                    en_transit: 1_000_000,
                    reste_a_recevoir: 500_000,
                    par_statut: { remise_en_cours: 1 },
                },
                filters: {
                    annee: '2026',
                    mois: '10',
                    site_ids: [],
                    statut: '',
                },
                sites: [{ id: 's-kankan', nom: 'Kankan' }],
                statut_options: [],
            },
            global: { renderStubDefaultSlot: true },
        });

        expect(wrapper.get('[data-testid="remise-reste"]').text()).toContain(
            '500 000',
        );
        expect(wrapper.get('[data-testid="remises-attendu"]').text()).toContain(
            '1 500 000',
        );
        expect(wrapper.text()).toContain('Octobre 2026');
        const statut = wrapper.findComponent(StatusDot);
        expect(statut.props('status')).toBe('remise_en_cours');
    });

    it('ne propose « Confirmer la réception » que si le serveur l’autorise', () => {
        const monter = (peutRecevoir: boolean) =>
            shallowMount(RemisesShow, {
                props: {
                    central: { id: 's-matoto', nom: 'Matoto' },
                    agence: { id: 's-kankan', nom: 'Kankan' },
                    ligne: LIGNE,
                    remises: [
                        {
                            id: 'm1',
                            reference: 'MVT-2026-00020',
                            nature_label: 'Transfert entre agences',
                            montant: 1_000_000,
                            date_envoi: '2026-10-03',
                            date_reception: null,
                            support_origine: 'Caisse Kankan',
                            support_destination: null,
                            envoye_par: 'Awa',
                            recu_par: null,
                            statut: 'envoye',
                            statut_label: 'Envoyé',
                            peut_recevoir: peutRecevoir,
                        },
                    ],
                    supports_reception: [
                        { id: 'c1', libelle: 'Caisse-espèce' },
                    ],
                    filters: { annee: '2026', mois: '10' },
                },
                global: {
                    renderStubDefaultSlot: true,
                    stubs: { Button: false, Dialog: true },
                },
            });

        expect(
            monter(false).find('[data-testid="remise-recevoir"]').exists(),
        ).toBe(false);

        expect(
            monter(true).find('[data-testid="remise-recevoir"]').exists(),
        ).toBe(true);
    });
});
