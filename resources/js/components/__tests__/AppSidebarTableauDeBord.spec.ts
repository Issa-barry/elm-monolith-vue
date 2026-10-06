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

const GROUPE = 'Tableau de bord';
const PREFIXE = 'Stat-';

/** Routes des vues statistiques du tableau de bord : toute entrée qui y mène est une « Stat- ». */
const estVueStatistique = (href: NavItem['href']) => {
    const url = typeof href === 'string' ? href : href.url;
    return (
        url === '/backoffice/dashboard' ||
        url.startsWith('/backoffice/tableau-de-bord/')
    );
};

/** Toutes les permissions accordées, pour voir le menu le plus complet possible. */
const TOUT = new Proxy({} as Record<string, boolean>, { get: () => true });

function items(perms: Record<string, boolean>): NavItem[] {
    permissions.valeur = perms;

    return shallowMount(AppSidebar, {
        global: { renderStubDefaultSlot: true },
    })
        .findComponent(NavMain)
        .props('items') as NavItem[];
}

/** Entrées et sous-entrées à plat, avec le groupe de leur entrée racine. */
function aPlat(liste: NavItem[]): (NavItem & { racine: NavItem })[] {
    const parcourir = (
        item: NavItem,
        racine: NavItem,
    ): (NavItem & { racine: NavItem })[] => [
        { ...item, racine },
        ...(item.items ?? []).flatMap((sous) => parcourir(sous, racine)),
    ];
    return liste.flatMap((item) => parcourir(item, item));
}

const tableauDeBord = (liste: NavItem[]) =>
    liste.filter((i) => i.group === GROUPE);

describe('AppSidebar — convention « Stat- » du tableau de bord', () => {
    it('préfixe par « Stat- » toutes les entrées du groupe Tableau de bord', () => {
        const entrees = tableauDeBord(items(TOUT));

        expect(entrees.length).toBeGreaterThan(0);
        for (const entree of entrees) {
            expect(entree.title.startsWith(PREFIXE), entree.title).toBe(true);
            for (const sous of entree.items ?? []) {
                expect(sous.title.startsWith(PREFIXE), sous.title).toBe(true);
            }
        }
    });

    it('affiche Stat-Ventes puis Stat-Commissions à la place de Ventes et Commissions', () => {
        const entrees = tableauDeBord(items(TOUT));

        expect(entrees.map((e) => e.title)).toEqual([
            'Stat-Ventes',
            'Stat-Commissions',
        ]);
        expect(entrees.map((e) => e.href)).toEqual([
            '/backoffice/dashboard',
            '/backoffice/tableau-de-bord/commissions',
        ]);
        expect(entrees.every((e) => e.icon !== undefined)).toBe(true);
    });

    it('range toute entrée menant à une vue statistique dans le Tableau de bord, préfixée', () => {
        const versStats = aPlat(items(TOUT)).filter((i) =>
            estVueStatistique(i.href),
        );

        expect(versStats.length).toBeGreaterThan(0);
        for (const entree of versStats) {
            expect(entree.racine.group, entree.title).toBe(GROUPE);
            expect(entree.title.startsWith(PREFIXE), entree.title).toBe(true);
        }
    });

    it('n’utilise jamais le préfixe « Stat- » hors du Tableau de bord', () => {
        const horsTableau = aPlat(items(TOUT)).filter(
            (i) => i.racine.group !== GROUPE,
        );

        expect(horsTableau.length).toBeGreaterThan(0);
        expect(horsTableau.filter((i) => i.title.startsWith(PREFIXE))).toEqual(
            [],
        );
    });

    it('laisse inchangés les vrais modules Ventes et Commissions dans leurs groupes métier', () => {
        const liste = items(TOUT);

        const ventes = liste.find((i) => i.title === 'Ventes');
        expect(ventes?.group).toBe('Commercial');
        expect(ventes?.items?.map((i) => i.href)).toContain(
            '/backoffice/ventes',
        );

        const comptabilite = liste.find((i) => i.title === 'Comptabilité');
        expect(comptabilite?.group).toBe('Finance');
        expect(comptabilite?.items?.map((i) => i.title)).toContain(
            'Commissions',
        );
    });

    it('n’introduit aucun doublon de libellé parmi les entrées de premier niveau', () => {
        const titres = items(TOUT).map((i) => i.title);

        expect(new Set(titres).size).toBe(titres.length);
    });

    it('conserve les permissions : Stat-Commissions exige comptabilite.read ou commissions.read', () => {
        expect(tableauDeBord(items({})).map((e) => e.title)).toEqual([
            'Stat-Ventes',
        ]);
        expect(
            tableauDeBord(items({ 'commissions.read': true })).map(
                (e) => e.title,
            ),
        ).toEqual(['Stat-Ventes', 'Stat-Commissions']);
        expect(
            tableauDeBord(items({ 'comptabilite.read': true })).map(
                (e) => e.title,
            ),
        ).toEqual(['Stat-Ventes', 'Stat-Commissions']);
    });
});
