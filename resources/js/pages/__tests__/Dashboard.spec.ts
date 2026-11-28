import HeaderWidget from '@/components/dashboard/banking/HeaderWidget.vue';
import MobileQuickMenu from '@/components/dashboard/banking/MobileQuickMenu.vue';
import Dashboard from '@/pages/Dashboard.vue';
import { shallowMount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent } = await import('vue');

    return { Head: defineComponent({ setup: () => () => null }) };
});
vi.mock('@/routes', () => ({
    dashboard: () => ({ url: '/backoffice/dashboard', method: 'get' }),
}));

const PERIODE = {
    cle: 'ce_mois',
    date_debut: null,
    date_fin: null,
    options: [],
    libelle: 'Ce mois',
};

describe('Page d’accueil du back-office (Dashboard.vue)', () => {
    it('Stat-Ventes désactivé : garde l’en-tête, le QR et le menu rapide, sans aucun indicateur', () => {
        const wrapper = shallowMount(Dashboard, {
            props: { ventes_actif: false, qr_payload: 'qr-123' },
            global: { renderStubDefaultSlot: true },
        });

        expect(wrapper.findComponent(HeaderWidget).props('qrPayload')).toBe(
            'qr-123',
        );
        expect(wrapper.findComponent(HeaderWidget).props('periode')).toBe(
            undefined,
        );
        expect(wrapper.findComponent(MobileQuickMenu).exists()).toBe(true);
        expect(wrapper.find('[data-testid="dashboard-ventes"]').exists()).toBe(
            false,
        );
        expect(wrapper.find('[data-testid="dashboard-periode"]').exists()).toBe(
            false,
        );
    });

    it('Stat-Ventes activé : affiche la période et les indicateurs de ventes', () => {
        const wrapper = shallowMount(Dashboard, {
            props: {
                ventes_actif: true,
                qr_payload: null,
                periode: PERIODE,
                agences: null,
                stats_factures: {
                    total_count: 0,
                    total_montant: 0,
                    payees_count: 0,
                    payees_montant: 0,
                    total_encaisse: 0,
                    impayees_count: 0,
                    annulees_count: 0,
                    reste_a_encaisser: 0,
                },
                evolution: { granularite: 'jour', tranches: [] },
                ca_par_site: [],
                ca_par_type_vehicule: [],
                ca_par_produit: [],
                ca_par_processus: [],
            },
            global: { renderStubDefaultSlot: true },
        });

        expect(wrapper.find('[data-testid="dashboard-ventes"]').exists()).toBe(
            true,
        );
        expect(
            wrapper.find('[data-testid="dashboard-periode"]').text(),
        ).toContain('Ce mois');
    });
});
