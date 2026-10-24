import MobileVenteList from '@/components/commande-vente/MobileVenteList.vue';
import type { VenteMobile } from '@/types/vente-mobile';
import { shallowMount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const permissions = new Set<string>();
vi.mock('@/composables/usePermissions', () => ({
    usePermissions: () => ({ can: (p: string) => permissions.has(p) }),
}));

const commande: VenteMobile = {
    id: '01JCOMMANDE',
    reference: 'VTE-171026-003',
    statut: 'livree',
    statut_label: 'Livrée',
    statut_affichage: { value: 'livree', label: 'Livrée' },
    processus_label: 'Vente',
    created_at: '17/10/2026',
    vehicule_nom: 'ACHOUR',
    vehicule_immatriculation: 'BG5084',
    vehicule_photo_url: null,
    chauffeur_nom: null,
    chauffeur_telephone: null,
    client_nom: null,
    client_telephone: null,
    site_nom: 'Matoto',
    total_commande: 8000000,
    facture_statut: null,
    facture_statut_label: null,
    facture_montant_encaisse: null,
    facture_montant_restant: null,
};

const ouvrirDetail = async () => {
    const wrapper = shallowMount(MobileVenteList, {
        props: { commandes: [commande] },
        global: { renderStubDefaultSlot: true },
    });
    await wrapper.find('button[aria-haspopup="dialog"]').trigger('click');

    return wrapper;
};

describe('Liste mobile des ventes — bon de commande', () => {
    beforeEach(() => permissions.clear());

    it('ouvre le bon de commande dans un nouvel onglet avec ventes.read', async () => {
        permissions.add('ventes.read');
        const lien = (await ouvrirDetail()).find(
            '[data-testid="telecharger-bon-mobile"]',
        );

        expect(lien.exists()).toBe(true);
        expect(lien.attributes('href')).toBe(
            '/backoffice/ventes/01JCOMMANDE/pdf?preview=1',
        );
        expect(lien.attributes('target')).toBe('_blank');
        expect(lien.attributes('rel')).toBe('noopener noreferrer');
        expect(lien.text()).toContain('Voir le bon de commande');
    });

    it('masque le bon de commande sans ventes.read', async () => {
        const wrapper = await ouvrirDetail();

        expect(
            wrapper.find('[data-testid="telecharger-bon-mobile"]').exists(),
        ).toBe(false);
    });
});
