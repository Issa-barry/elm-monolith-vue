import AppSidebar from '@/components/AppSidebar.vue';
import NavMain from '@/components/NavMain.vue';
import type { NavItem } from '@/types';
import { shallowMount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const permissions = vi.hoisted(() => ({
    valeur: {} as Record<string, boolean>,
}));

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
            props: { auth: { permissions: permissions.valeur, roles: [] } },
        }),
    };
});
vi.mock('@/routes', () => ({
    dashboard: () => ({ url: '/backoffice/dashboard', method: 'get' }),
    home: () => ({ url: '/', method: 'get' }),
}));

function items(perms: Record<string, boolean>): NavItem[] {
    permissions.valeur = perms;

    return shallowMount(AppSidebar, {
        global: { renderStubDefaultSlot: true },
    })
        .findComponent(NavMain)
        .props('items') as NavItem[];
}

const rapports = (liste: NavItem[]) =>
    liste.find((i) => i.title === 'Rapports');

describe('AppSidebar — navigation Rapports', () => {
    it('regroupe Ma situation et Rapport d’activité sous Rapports (Pilotage)', () => {
        const liste = items({
            'rapports.read_own': true,
            'rapports.read': true,
        });

        expect(liste[0].title).toBe('Tableau de bord');
        expect(liste.some((i) => i.title === 'Ma situation')).toBe(false);
        expect(rapports(liste)?.group).toBe('Pilotage');
        expect(rapports(liste)?.items?.map((i) => i.title)).toEqual([
            'Ma situation',
            "Rapport d'activité",
        ]);
    });

    it('un agent sans rapports.read ne voit que Ma situation', () => {
        const liste = items({ 'rapports.read_own': true });

        expect(rapports(liste)?.items?.map((i) => i.title)).toEqual([
            'Ma situation',
        ]);
        expect(rapports(liste)?.href).toBe('/backoffice/ma-situation');
    });

    it('sans permission de rapport, pas de menu Rapports', () => {
        expect(rapports(items({}))).toBeUndefined();
    });
});
