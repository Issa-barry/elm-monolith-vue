import StatusDot from '@/components/StatusDot.vue';
import FinancementIndex from '@/pages/Comptabilite/Financement/Index.vue';
import { shallowMount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/composables/usePermissions', () => ({
    usePermissions: () => ({ can: () => true }),
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
    const { defineComponent, h } = await import('vue');

    return {
        Head: defineComponent({ setup: () => () => null }),
        Link: defineComponent({
            setup:
                (_, { slots }) =>
                () =>
                    h('a', slots.default?.()),
        }),
        router: { get: vi.fn() },
    };
});

const ligne = (surcharge: Record<string, unknown>) => ({
    site_id: 's-kankan',
    site_nom: 'Kankan',
    livreurs_p1: 1_500_000,
    livreurs_p2: 0,
    proprietaires: 0,
    salaires: 0,
    total_a_regler: 1_500_000,
    arrieres: 0,
    a_conserver: 1_500_000,
    disponible: 2_000_000,
    fonds_autres_agences: 1_000_000,
    disponible_propre: 1_000_000,
    fonds_en_transit: 0,
    deja_finance: 0,
    a_financer: 500_000,
    remise_obligatoire: 1_000_000,
    excedent_a_remettre: 0,
    total_a_remettre: 1_000_000,
    est_tresorerie_principale: false,
    statut: 'a_financer',
    ...surcharge,
});

const monter = (rows: Record<string, unknown>[]) =>
    shallowMount(FinancementIndex, {
        props: {
            // Lignes telles que renvoyées par FinancementAgenceController (type Row interne à la page).
            rows: rows as never,
            total_general: {
                total_a_regler: 1_500_000,
                arrieres: 0,
                a_conserver: 1_500_000,
                disponible: 2_000_000,
                fonds_autres_agences: 1_000_000,
                fonds_en_transit: 0,
                deja_finance: 0,
                a_financer: 500_000,
                total_a_remettre: 1_000_000,
            },
            filters: {
                annee: '2026',
                mois: '10',
                echeance: 'p1',
                site_ids: [],
            },
            echeance_debut: '2026-10-01',
            echeance_fin: '2026-10-15',
            sites: [],
            is_admin: true,
        },
        global: { renderStubDefaultSlot: true },
    });

describe('Financement des agences — remise à la trésorerie principale (ADR 0016)', () => {
    it('affiche l’argent d’autres agences, le montant à remettre et le besoin de financement', () => {
        const wrapper = monter([ligne({})]);

        expect(
            wrapper.get('[data-testid="financement-autres-agences"]').text(),
        ).toContain('1 000 000');
        const remise = wrapper.get('[data-testid="financement-a-remettre"]');
        expect(remise.text()).toContain('1 000 000');
        expect(remise.attributes('title')).toContain("Argent d'autres agences");
        expect(
            wrapper.get('[data-testid="financement-total-a-remettre"]').text(),
        ).toContain('1 000 000');
        expect(wrapper.text()).toContain(
            'À financer par la trésorerie principale',
        );
        expect(wrapper.text()).not.toContain('par le siège');
    });

    it('n’affiche ni remise ni financement pour la trésorerie principale', () => {
        const wrapper = monter([
            ligne({
                site_id: 's-matoto',
                site_nom: 'Matoto',
                fonds_autres_agences: null,
                a_financer: null,
                remise_obligatoire: null,
                excedent_a_remettre: null,
                total_a_remettre: null,
                est_tresorerie_principale: true,
                statut: 'tresorerie_principale',
            }),
        ]);

        expect(
            wrapper.get('[data-testid="financement-a-remettre"]').text(),
        ).toBe('—');
        const statut = wrapper.findComponent(StatusDot);
        expect(statut.props('status')).toBe('tresorerie_principale');
        expect(statut.props('label')).toBe('Trésorerie principale');
    });
});
