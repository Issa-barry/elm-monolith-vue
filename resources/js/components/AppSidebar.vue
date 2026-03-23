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
import { BookOpen, Car, Folder, Home, LayoutGrid, Layers, Package, Truck, Users } from 'lucide-vue-next';
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

    if (can('proprietaires.read')) {
        items.push({
            title: 'Propriétaires',
            href: '/proprietaires',
            icon: Home,
        });
    }

    if (can('livreurs.read')) {
        items.push({
            title: 'Livreurs',
            href: '/livreurs',
            icon: Truck,
        });
    }

    if (can('vehicules.read')) {
        items.push({
            title: 'Véhicules',
            href: '/vehicules',
            icon: Car,
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
