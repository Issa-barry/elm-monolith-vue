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
    UserCog,
    UserRoundCheck,
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
const vehiculesItems = computed((): QuickMenuHref[] => {
    if (!moduleActive('vehicules')) return [];

    const items: QuickMenuHref[] = [];
    if (can('proprietaires.read')) items.push('/proprietaires');
    if (can('vehicules.read')) items.push('/vehicules');
    if (can('equipes-livraison.read')) items.push('/equipes-livraison');
    return items;
});
const vehiculesQuickHref = computed<QuickMenuHref>(
    () => vehiculesItems.value[0] ?? '/vehicules',
);

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
            title: 'Clients',
            href: '/clients',
            icon: UserRoundCheck,
            visible: can('clients.read'),
        },
        {
            title: 'Prestataires',
            href: '/prestataires',
            icon: HandCoins,
            visible: canSee('prestataires.read', 'prestataires'),
        },
        {
            title: 'Vehicules',
            href: vehiculesQuickHref.value,
            icon: Car,
            visible: vehiculesItems.value.length > 0,
        },
        {
            title: 'Produits',
            href: '/produits',
            icon: Package,
            visible: canSee('produits.read', 'produits'),
        },
        {
            title: 'Logistique',
            href: '/logistique/transferts',
            icon: Truck,
            visible: canSee('logistique.read', 'logistique'),
        },
        {
            title: 'Sites',
            href: '/sites',
            icon: Building2,
            visible: canSee('sites.read', 'sites'),
        },
        {
            title: 'Utilisateurs',
            href: '/users',
            icon: UserCog,
            visible: canSee('users.read', 'utilisateurs'),
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
