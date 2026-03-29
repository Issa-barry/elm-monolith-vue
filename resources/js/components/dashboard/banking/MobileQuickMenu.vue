<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import { toUrl } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import { Link, usePage } from '@inertiajs/vue3';
import type { LucideIcon } from 'lucide-vue-next';
import {
    Building2,
    Car,
    HandCoins,
    House,
    Layers,
    Package,
    PackageCheck,
    ShoppingCart,
    Truck,
    UserRound,
} from 'lucide-vue-next';
import { computed } from 'vue';

type QuickMenuHref = NonNullable<InertiaLinkProps['href']>;

interface QuickMenuItem {
    title: string;
    href: QuickMenuHref;
    icon: LucideIcon;
    visible: boolean;
}

const page = usePage();
const { can } = usePermissions();

const quickMenuItems = computed((): QuickMenuItem[] =>
    [
        {
            title: 'Accueil',
            href: dashboard().url,
            icon: House,
            visible: true,
        },
        {
            title: 'Ventes',
            href: '/ventes',
            icon: ShoppingCart,
            visible: can('ventes.read'),
        },
        {
            title: 'Achats',
            href: '/achats',
            icon: PackageCheck,
            visible: can('achats.read'),
        },
        {
            title: 'Packings',
            href: '/packings',
            icon: Layers,
            visible: can('packings.read'),
        },
        {
            title: 'Prestataires',
            href: '/prestataires',
            icon: HandCoins,
            visible: can('prestataires.read'),
        },
        {
            title: 'Vehicules',
            href: '/vehicules',
            icon: Car,
            visible: can('vehicules.read'),
        },
        {
            title: 'Livreurs',
            href: '/livreurs',
            icon: Truck,
            visible: can('livreurs.read'),
        },
        {
            title: 'Proprietaires',
            href: '/proprietaires',
            icon: UserRound,
            visible: can('proprietaires.read'),
        },
        {
            title: 'Produits',
            href: '/produits',
            icon: Package,
            visible: can('produits.read'),
        },
        {
            title: 'Sites',
            href: '/sites',
            icon: Building2,
            visible: can('sites.read'),
        },
    ].filter((item) => item.visible),
);

function isItemActive(href: QuickMenuHref) {
    const url = toUrl(href);
    if (!url) return false;

    return (
        page.url === url ||
        page.url.startsWith(`${url}/`) ||
        page.url.startsWith(`${url}?`)
    );
}
</script>

<template>
    <div v-if="quickMenuItems.length" class="sm:hidden">
        <div class="grid grid-cols-3 gap-2.5">
            <Link
                v-for="item in quickMenuItems"
                :key="item.title"
                :href="item.href"
                class="flex min-h-[74px] flex-col items-center justify-center gap-1.5 rounded-xl border px-2 py-2 text-center transition-colors"
                :class="
                    isItemActive(item.href)
                        ? 'border-primary/30 bg-primary/5 text-primary'
                        : 'border-border bg-card text-muted-foreground'
                "
            >
                <component :is="item.icon" class="h-4 w-4" />
                <span class="text-[11px] leading-tight font-medium">{{
                    item.title
                }}</span>
            </Link>
        </div>
    </div>
</template>
