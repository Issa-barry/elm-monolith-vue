import AppSidebar from '@/components/AppSidebar.vue';
import NavMain from '@/components/NavMain.vue';
import type { NavItem } from '@/types';
import { shallowMount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const permissions = vi.hoisted(() => ({
    valeur: {} as Record<string, boolean>,
}));
// Tableaux de bord activés pour l'organisation (props partagées, ADR 0071).
const tableaux = vi.hoisted(() => ({
    valeur: undefined as Record<string, boolean> | undefined,
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
            props: {
                auth: { permissions: permissions.valeur, roles: [] },
                tableaux_de_bord: tableaux.valeur,
            },
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

/** Les six tableaux de bord activés : ces tests portent sur les permissions et la convention. */
const TOUS_ACTIFS = {
    ventes: true,
    commissions: true,
    depenses: true,
    stock: true,
    rh: true,
    vehicules: true,
};

function items(
    perms: Record<string, boolean>,
    actifs: Record<string, boolean> | null = TOUS_ACTIFS,
): NavItem[] {
    permissions.valeur = perms;
    // null : le serveur n'a transmis aucun état.
    tableaux.valeur = actifs ?? undefined;

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

    it('affiche Stat-Ventes, Stat-Commissions, Stat-Dépenses, Stat-Stock, Stat-RH puis Stat-Véhicules dans le Tableau de bord', () => {
        const entrees = tableauDeBord(items(TOUT));

        expect(entrees.map((e) => e.title)).toEqual([
            'Stat-Ventes',
            'Stat-Commissions',
            'Stat-Dépenses',
            'Stat-Stock',
            'Stat-RH',
            'Stat-Véhicules',
        ]);
        expect(entrees.map((e) => e.href)).toEqual([
            '/backoffice/dashboard',
            '/backoffice/tableau-de-bord/commissions',
            '/backoffice/tableau-de-bord/depenses',
            '/backoffice/tableau-de-bord/stock',
            '/backoffice/tableau-de-bord/rh',
            '/backoffice/tableau-de-bord/vehicules',
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

    it('conserve les permissions : Stat-Dépenses exige depenses.read, comme la liste des dépenses', () => {
        expect(
            tableauDeBord(items({ 'depenses.read': true })).map((e) => e.title),
        ).toEqual(['Stat-Ventes', 'Stat-Dépenses']);
    });

    it('conserve les permissions : Stat-Stock exige produits.read, comme la page Stock', () => {
        expect(
            tableauDeBord(items({ 'produits.read': true })).map((e) => e.title),
        ).toEqual(['Stat-Ventes', 'Stat-Stock']);
    });

    it('ouvre Stat-RH avec l’une des listes Employés, Contrats ou Absences', () => {
        for (const permission of [
            'rh-employes.read',
            'rh-contrats.read',
            'rh-absences.read',
        ]) {
            expect(
                tableauDeBord(items({ [permission]: true })).map(
                    (e) => e.title,
                ),
                permission,
            ).toEqual(['Stat-Ventes', 'Stat-RH']);
        }
        expect(
            tableauDeBord(items({ 'rh-transmissions-paie.read': true })).map(
                (e) => e.title,
            ),
        ).toEqual(['Stat-Ventes']);
    });

    it('conserve les permissions : Stat-Véhicules exige vehicules.read, comme la liste des véhicules', () => {
        expect(
            tableauDeBord(items({ 'vehicules.read': true })).map(
                (e) => e.title,
            ),
        ).toEqual(['Stat-Ventes', 'Stat-Véhicules']);
        // Lire les ventes ou les dépenses n'ouvre pas le tableau de bord du parc.
        expect(
            tableauDeBord(
                items({ 'ventes.read': true, 'logistique.read': true }),
            ).map((e) => e.title),
        ).toEqual(['Stat-Ventes']);
    });
});

describe('AppSidebar — activation des tableaux de bord par organisation (ADR 0071)', () => {
    const titres = (actifs: Record<string, boolean> | null) =>
        tableauDeBord(items(TOUT, actifs)).map((e) => e.title);

    it('n’affiche par défaut que Stat-Ventes et Stat-Stock', () => {
        expect(
            titres({
                ventes: true,
                commissions: false,
                depenses: false,
                stock: true,
                rh: false,
                vehicules: false,
            }),
        ).toEqual(['Stat-Ventes', 'Stat-Stock']);
    });

    it('retire du menu chaque tableau désactivé, sans changer l’ordre des autres', () => {
        const ordre = [
            ['ventes', 'Stat-Ventes'],
            ['commissions', 'Stat-Commissions'],
            ['depenses', 'Stat-Dépenses'],
            ['stock', 'Stat-Stock'],
            ['rh', 'Stat-RH'],
            ['vehicules', 'Stat-Véhicules'],
        ];

        for (const [cle, titre] of ordre) {
            expect(titres({ ...TOUS_ACTIFS, [cle]: false }), cle).toEqual(
                ordre.map(([, t]) => t).filter((t) => t !== titre),
            );
        }
    });

    it('fait disparaître le groupe Tableau de bord quand aucun tableau n’est activé', () => {
        const aucun = Object.fromEntries(
            Object.keys(TOUS_ACTIFS).map((cle) => [cle, false]),
        );

        expect(titres(aucun)).toEqual([]);
        // Sans état transmis par le serveur, aucun tableau n'est supposé activé.
        expect(titres(null)).toEqual([]);
    });

    it('n’ouvre jamais un tableau activé sans la permission de l’utilisateur', () => {
        expect(
            tableauDeBord(items({}, TOUS_ACTIFS)).map((e) => e.title),
        ).toEqual(['Stat-Ventes']);
    });
});
