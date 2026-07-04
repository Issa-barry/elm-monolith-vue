<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { usePermissions } from '@/composables/usePermissions';
import { dashboard, home } from '@/routes';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Briefcase,
    Building2,
    Calculator,
    Car,
    Layers,
    LayoutGrid,
    Package,
    PackageCheck,
    Receipt,
    ShoppingCart,
    Truck,
    UserCog,
    UserRoundCheck,
    Users,
    UsersRound,
} from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const { can } = usePermissions();
const page = usePage();
const isSuperAdmin = computed(() =>
    (page.props as any).auth?.roles?.includes('super_admin'),
);
const stockAlertes = computed(
    () => (page.props as any).stock_alertes ?? { total: 0 },
);
const moduleFlags = computed(
    () => ((page.props as any).module_flags as Record<string, boolean>) ?? {},
);
const moduleActive = (key: string): boolean => moduleFlags.value[key] !== false;
const transfertsAReceptionner = computed(
    () => ((page.props as any).transferts_a_receptionner as number) ?? 0,
);

/** Guard combiné permission + module actif */
const canSee = (permission: string, module: string): boolean =>
    can(permission) && moduleActive(module);

const rhItems = computed((): NavItem[] => {
    if (!moduleActive('rh')) return [];
    const sub: NavItem[] = [];
    if (can('rh-employes.read'))
        sub.push({ title: 'Employés', href: '/backoffice/employes' });
    if (can('rh-contrats.read'))
        sub.push({ title: 'Contrats', href: '/backoffice/contrats' });
    return sub;
});

/** Sous-items Véhicules (calculés séparément pour limiter la complexité) */
const propositionsATraiter = computed(
    () => ((page.props as any).propositions_a_traiter as number) ?? 0,
);

const vehiculesItems = computed((): NavItem[] => {
    if (!moduleActive('vehicules')) return [];
    const sub: NavItem[] = [];
    if (can('proprietaires.read'))
        sub.push({ title: 'Propriétaires', href: '/backoffice/proprietaires' });
    if (can('vehicules.read'))
        sub.push({
            title: 'Liste de véhicules',
            href: '/backoffice/vehicules',
        });
    if (can('equipes-livraison.read'))
        sub.push({
            title: 'Équipes de livraison',
            href: '/backoffice/equipes-livraison',
        });
    if (can('type-vehicules.read'))
        sub.push({
            title: 'Types de véhicules',
            href: '/backoffice/type-vehicules',
        });
    if (can('propositions.read'))
        sub.push({
            title: 'Propositions',
            href: '/backoffice/vehicules/propositions',
            badge:
                propositionsATraiter.value > 0
                    ? propositionsATraiter.value
                    : undefined,
        });
    return sub;
});

const mainNavItems = computed((): NavItem[] => {
    const items: NavItem[] = [
        { title: 'Tableau de bord', href: dashboard(), icon: LayoutGrid },
    ];

    if (canSee('ventes.read', 'ventes')) {
        const ventesSubItems = [
            { title: 'Commandes', href: '/backoffice/ventes' },
        ];
        if (moduleActive('pdv')) {
            ventesSubItems.push({ title: 'PDV', href: '/backoffice/pdv' });
        }
        ventesSubItems.push({
            title: 'Factures',
            href: '/backoffice/factures',
        });
        if (moduleActive('cashback')) {
            ventesSubItems.push({
                title: 'Cashback',
                href: '/backoffice/cashback',
            });
        }
        items.push({
            title: 'Ventes',
            href: '/backoffice/ventes',
            icon: ShoppingCart,
            items: ventesSubItems,
        });
    }

    if (canSee('achats.read', 'achats'))
        items.push({
            title: 'Achats',
            href: '/backoffice/achats',
            icon: PackageCheck,
        });
    if (canSee('packings.read', 'packings'))
        items.push({
            title: 'Packings',
            href: '/backoffice/packings',
            icon: Layers,
        });
    if (can('clients.read'))
        items.push({
            title: 'Clients',
            href: '/backoffice/clients',
            icon: UserRoundCheck,
        });

    if (canSee('prestataires.read', 'prestataires'))
        items.push({
            title: 'Prestataires',
            href: '/backoffice/prestataires',
            icon: Users,
        });

    if (vehiculesItems.value.length > 0) {
        items.push({
            title: 'Véhicules',
            href: vehiculesItems.value[0].href,
            icon: Car,
            items: vehiculesItems.value,
        });
    }

    if (canSee('produits.read', 'produits')) {
        items.push({
            title: 'Produits',
            href: '/backoffice/produits',
            icon: Package,
            badge:
                stockAlertes.value.total > 0
                    ? stockAlertes.value.total
                    : undefined,
        });
    }

    if (moduleActive('logistique') && can('logistique.read')) {
        items.push({
            title: 'Logistique',
            href: '/backoffice/logistique/transferts',
            icon: Truck,
            items: [
                {
                    title: 'Transferts',
                    href: '/backoffice/logistique/transferts',
                },
                {
                    title: 'Réceptions',
                    href: '/backoffice/logistique/receptions',
                    badge:
                        transfertsAReceptionner.value > 0
                            ? transfertsAReceptionner.value
                            : undefined,
                },
            ],
        });
    }

    if (canSee('depenses.read', 'depenses'))
        items.push({
            title: 'Dépenses',
            href: '/backoffice/depenses',
            icon: Receipt,
        });

    if (rhItems.value.length > 0) {
        items.push({
            title: 'RH',
            href: rhItems.value[0].href,
            icon: Briefcase,
            items: rhItems.value,
        });
    }

    if (canSee('comptabilite.read', 'comptabilite')) {
        items.push({
            title: 'Comptabilité',
            href: '/backoffice/comptabilite',
            icon: Calculator,
            items: [
                { title: 'Tableau de bord', href: '/backoffice/comptabilite' },
                {
                    title: 'Commission logistique',
                    href: '/backoffice/comptabilite/commissions/logistique',
                },
                {
                    title: 'Commission vente',
                    href: '/backoffice/comptabilite/commissions/vente',
                },
                {
                    title: 'Commission propriétaire',
                    href: '/backoffice/comptabilite/commissions/proprietaires',
                },
                {
                    title: 'Périodes',
                    href: '/backoffice/comptabilite/periodes',
                },
                {
                    title: 'Paiement salaire',
                    href: '/backoffice/comptabilite/salaires',
                },
                {
                    title: 'Journal financier',
                    href: '/backoffice/comptabilite/journal',
                },
            ],
        });
    }

    if (canSee('sites.read', 'sites'))
        items.push({
            title: 'Sites',
            href: '/backoffice/sites',
            icon: Building2,
        });

    if (canSee('users.read', 'utilisateurs'))
        items.push({
            title: 'Utilisateurs',
            href: '/backoffice/users',
            icon: UserCog,
        });

    if (isSuperAdmin.value)
        items.push({
            title: 'Comptes',
            href: '/backoffice/comptes',
            icon: UsersRound,
        });

    return items;
});

const footerNavItems: NavItem[] = [];
</script>

<template>
    <Sidebar collapsible="icon" variant="sidebar">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="home()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter v-if="footerNavItems.length" :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
