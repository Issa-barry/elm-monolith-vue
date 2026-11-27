import AppSidebar from '@/components/AppSidebar.vue';
import { shallowMount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Link: defineComponent({
            setup:
                (_, { slots }) =>
                () =>
                    h('a', slots.default?.()),
        }),
        usePage: () => ({
            props: {
                auth: {
                    user: {
                        prenom: 'Awa',
                        nom: 'Camara',
                        email: 'a@example.test',
                    },
                    permissions: new Proxy({}, { get: () => true }),
                    roles: ['admin_entreprise'],
                    default_site: { label: 'Usine de Matoto' },
                },
            },
        }),
    };
});
vi.mock('@/routes', () => ({
    dashboard: () => ({ url: '/backoffice/dashboard', method: 'get' }),
    home: () => ({ url: '/', method: 'get' }),
}));

describe('AppSidebar — navigation seule, compte dans la barre supérieure', () => {
    it("n'affiche plus le bloc utilisateur en pied de menu", () => {
        const wrapper = shallowMount(AppSidebar, {
            global: { renderStubDefaultSlot: true },
        });

        expect(wrapper.findComponent({ name: 'SidebarFooter' }).exists()).toBe(
            false,
        );
        expect(wrapper.findComponent({ name: 'SidebarContent' }).exists()).toBe(
            true,
        );
        expect(wrapper.text()).not.toContain('Awa Camara');
        expect(wrapper.text()).not.toContain('Usine de Matoto');
    });
});
