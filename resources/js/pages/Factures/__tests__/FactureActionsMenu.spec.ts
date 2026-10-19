import FactureActionsMenu from '@/pages/Factures/partials/FactureActionsMenu.vue';
import { shallowMount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const permissions = new Set<string>();
vi.mock('@/composables/usePermissions', () => ({
    usePermissions: () => ({ can: (p: string) => permissions.has(p) }),
}));

const facture = (surcharge: Record<string, unknown> = {}) => ({
    id: '01JFACTURE',
    reference: 'CMD-181026-004',
    is_annulee: false,
    is_payee: false,
    is_encaissable: true,
    ...surcharge,
});

const monter = (surcharge: Record<string, unknown> = {}) =>
    shallowMount(FactureActionsMenu, {
        props: { facture: facture(surcharge) },
        global: { renderStubDefaultSlot: true },
    });

const actions = (wrapper: ReturnType<typeof monter>) =>
    wrapper
        .findAll('[data-testid^="action-"]')
        .map((el) => el.attributes('data-testid'));

describe('Factures de vente — menu de ligne', () => {
    beforeEach(() => {
        permissions.clear();
        permissions.add('ventes.read');
        permissions.add('ventes.update');
    });

    it('place « Télécharger la facture » immédiatement sous « Encaisser »', () => {
        const wrapper = monter();

        expect(actions(wrapper)).toEqual([
            'action-historique',
            'action-encaisser',
            'action-telecharger',
        ]);
        const lien = wrapper.find('[data-testid="action-telecharger"] a');
        expect(lien.attributes('href')).toBe(
            '/backoffice/factures/01JFACTURE/pdf',
        );
        expect(lien.attributes('download')).toBeDefined();
        expect(lien.text()).toContain('Télécharger la facture');
    });

    it('garde le téléchargement pour une facture payée, sans « Encaisser »', () => {
        const wrapper = monter({ is_payee: true });

        expect(actions(wrapper)).toEqual([
            'action-historique',
            'action-telecharger',
        ]);
    });

    it('garde le téléchargement sans droit d’encaissement', () => {
        permissions.delete('ventes.update');

        expect(actions(monter())).toEqual([
            'action-historique',
            'action-telecharger',
        ]);
    });

    it('n’affiche pas le téléchargement sans la permission de consultation', () => {
        permissions.clear();

        expect(actions(monter())).toEqual(['action-historique']);
    });
});
