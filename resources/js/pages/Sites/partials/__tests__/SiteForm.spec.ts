import { mount } from '@vue/test-utils';
import PrimeVue from 'primevue/config';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SiteForm from '../SiteForm.vue';

const permissions = vi.hoisted(() => ({ accordees: new Set<string>() }));

vi.mock('@/composables/usePermissions', () => ({
    usePermissions: () => ({
        can: (permission: string) => permissions.accordees.has(permission),
    }),
}));

const form = {
    nom: 'Matoto',
    code: '001',
    type: 'agence',
    ville: 'Conakry',
    quartier: 'Matoto',
    telephone: null,
    commissions_active: true,
    is_central_tresorerie: true,
};

function monter(props: Record<string, unknown> = {}) {
    return mount(SiteForm, {
        props: { form, errors: {}, processing: false, types: [], ...props },
        global: { plugins: [PrimeVue], stubs: { Select: true } },
    });
}

const interrupteur = '[data-testid="site-tresorerie-principale"]';

describe('SiteForm — trésorerie principale', () => {
    beforeEach(() => permissions.accordees.clear());

    it("masque l'interrupteur sans la permission tresorerie.designer_principale", () => {
        permissions.accordees.add('sites.update');

        expect(monter().find(interrupteur).exists()).toBe(false);
    });

    it("affiche l'interrupteur avec la permission", () => {
        permissions.accordees.add('tresorerie.designer_principale');
        const wrapper = monter({ estTresoreriePrincipale: false });

        expect(wrapper.find(interrupteur).exists()).toBe(true);
        expect(
            wrapper.find(interrupteur).attributes('disabled'),
        ).toBeUndefined();
    });

    it('verrouille l’interrupteur sur la trésorerie principale actuelle', () => {
        permissions.accordees.add('tresorerie.designer_principale');
        const wrapper = monter({ estTresoreriePrincipale: true });

        expect(wrapper.find(interrupteur).attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain(
            'Pour la transférer, activez-la sur un autre site.',
        );
    });
});
