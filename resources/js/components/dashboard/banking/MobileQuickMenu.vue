<script setup lang="ts">
import { usePermissions } from '@/composables/usePermissions';
import { toUrl } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { AppPageProps, ModuleFlagKey, PermissionKey } from '@/types';
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

const page = usePage<AppPageProps>();
const { can } = usePermissions();
const moduleFlags = computed(() => page.props.module_flags ?? {});
const moduleActive = (key: ModuleFlagKey): boolean =>
    moduleFlags.value[key] !== false;
const canSee = (permission: PermissionKey, module: ModuleFlagKey): boolean =>
    can(permission) && moduleActive(module);

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
            visible: canSee('ventes.read', 'ventes'),
        },
        {
            title: 'Achats',
            href: '/achats',
            icon: PackageCheck,
            visible: canSee('achats.read', 'achats'),
        },
        {
            title: 'Packings',
            href: '/packings',
            icon: Layers,
            visible: canSee('packings.read', 'packings'),
        },
        {
            title: 'Prestataires',
            href: '/prestataires',
            icon: HandCoins,
            visible: canSee('prestataires.read', 'prestataires'),
        },
        {
            title: 'Vehicules',
            href: '/vehicules',
            icon: Car,
            visible: canSee('vehicules.read', 'vehicules'),
        },
        {
            title: 'Livreurs',
            href: '/livreurs',
            icon: Truck,
            visible: canSee('livreurs.read', 'vehicules'),
        },
        {
            title: 'Proprietaires',
            href: '/proprietaires',
            icon: UserRound,
            visible: canSee('proprietaires.read', 'vehicules'),
        },
        {
            title: 'Produits',
            href: '/produits',
            icon: Package,
            visible: canSee('produits.read', 'produits'),
        },
        {
            title: 'Sites',
            href: '/sites',
            icon: Building2,
            visible: canSee('sites.read', 'sites'),
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
