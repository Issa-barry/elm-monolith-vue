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
    Building2,
    Car,
    Layers,
    LayoutGrid,
    Package,
    PackageCheck,
    ShoppingCart,
    UserCog,
    UserRoundCheck,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const { can } = usePermissions();
const page = usePage();
const stockAlertes = computed(
    () => (page.props as any).stock_alertes ?? { total: 0 },
);
const moduleFlags = computed(
    () => ((page.props as any).module_flags as Record<string, boolean>) ?? {},
);
const moduleActive = (key: string): boolean => moduleFlags.value[key] !== false;

/** Guard combiné permission + module actif */
const canSee = (permission: string, module: string): boolean =>
    can(permission) && moduleActive(module);

/** Sous-items Véhicules (calculés séparément pour limiter la complexité) */
const vehiculesItems = computed((): NavItem[] => {
    if (!moduleActive('vehicules')) return [];
    const sub: NavItem[] = [];
    if (can('vehicules.read'))
        sub.push({ title: 'Liste de véhicules', href: '/vehicules' });
    if (can('proprietaires.read'))
        sub.push({ title: 'Propriétaires', href: '/proprietaires' });
    if (can('equipes-livraison.read'))
        sub.push({ title: 'Équipes de livraison', href: '/equipes-livraison' });
    return sub;
});

const mainNavItems = computed((): NavItem[] => {
    const items: NavItem[] = [
        { title: 'Tableau de bord', href: dashboard(), icon: LayoutGrid },
    ];

    if (canSee('ventes.read', 'ventes')) {
        items.push({
            title: 'Ventes',
            href: '/ventes',
            icon: ShoppingCart,
            items: [
                { title: 'Commandes', href: '/ventes' },
                { title: 'Factures', href: '/factures' },
                { title: 'Commissions', href: '/commissions' },
            ],
        });
    }

    if (canSee('achats.read', 'achats'))
        items.push({ title: 'Achats', href: '/achats', icon: PackageCheck });
    if (canSee('packings.read', 'packings'))
        items.push({ title: 'Packings', href: '/packings', icon: Layers });
    if (can('clients.read'))
        items.push({
            title: 'Clients',
            href: '/clients',
            icon: UserRoundCheck,
        });

    if (canSee('prestataires.read', 'prestataires'))
        items.push({
            title: 'Prestataires',
            href: '/prestataires',
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
            href: '/produits',
            icon: Package,
            badge:
                stockAlertes.value.total > 0
                    ? stockAlertes.value.total
                    : undefined,
        });
    }

    if (canSee('sites.read', 'sites'))
        items.push({ title: 'Sites', href: '/sites', icon: Building2 });
    if (canSee('users.read', 'utilisateurs'))
        items.push({ title: 'Utilisateurs', href: '/users', icon: UserCog });

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
