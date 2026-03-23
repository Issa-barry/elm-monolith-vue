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
import { dashboard } from '@/routes';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { Building2, Car, FileText, LayoutGrid, Layers, Package, ShoppingCart, Users } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const { can } = usePermissions();

const mainNavItems = computed((): NavItem[] => {
    const items: NavItem[] = [
        {
            title: 'Tableau de bord',
            href: dashboard(),
            icon: LayoutGrid,
        },
    ];

    if (can('produits.read')) {
        items.push({
            title: 'Produits',
            href: '/produits',
            icon: Package,
        });
    }

    if (can('prestataires.read')) {
        items.push({
            title: 'Prestataires',
            href: '/prestataires',
            icon: Users,
        });
    }

    if (can('packings.read')) {
        items.push({
            title: 'Packings',
            href: '/packings',
            icon: Layers,
        });
    }

    if (can('ventes.read')) {
        items.push({
            title: 'Ventes',
            href: '/ventes',
            icon: ShoppingCart,
            items: [
                { title: 'Commandes', href: '/ventes' },
                { title: 'Factures', href: '/factures' },
            ],
        });
    }

    const vehiculesSubItems: NavItem[] = [];

    if (can('vehicules.read')) {
        vehiculesSubItems.push({
            title: 'Liste de véhicules',
            href: '/vehicules',
        });
    }

    if (can('proprietaires.read')) {
        vehiculesSubItems.push({
            title: 'Propriétaires',
            href: '/proprietaires',
        });
    }

    if (can('livreurs.read')) {
        vehiculesSubItems.push({
            title: 'Livreurs',
            href: '/livreurs',
        });
    }

    if (vehiculesSubItems.length > 0) {
        items.push({
            title: 'Véhicules',
            href: vehiculesSubItems[0].href,
            icon: Car,
            items: vehiculesSubItems,
        });
    }

    if (can('sites.read')) {
        items.push({
            title: 'Sites',
            href: '/sites',
            icon: Building2,
        });
    }

    return items;
});

const footerNavItems: NavItem[] = [];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
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
