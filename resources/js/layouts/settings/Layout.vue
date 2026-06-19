<script setup lang="ts">
defineProps<{ wide?: boolean }>();

import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { usePermissions } from '@/composables/usePermissions';
import { toUrl, urlIsActive } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editParametres } from '@/routes/parametres';
import { edit as editProfile } from '@/routes/profile';
import { show } from '@/routes/two-factor';
import { edit as editPassword } from '@/routes/user-password';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const { can, hasRole } = usePermissions();

const isAdmin = computed(
    () => hasRole('super_admin') || hasRole('admin_entreprise'),
);

const sidebarNavItems = computed((): NavItem[] => {
    const items: NavItem[] = [
        { title: 'Profil', href: editProfile() },
        { title: 'Mot de passe', href: editPassword() },
        { title: 'Double authentification', href: show() },
        { title: 'Apparence', href: editAppearance() },
    ];

    if (isAdmin.value) {
        items.push({ title: 'Roles & Permissions', href: '/roles' });
    }

    if (can('parametres.update')) {
        items.push(
            { title: 'Parametrage systeme', href: editParametres().url },
            { title: 'Paramètres produits', href: '/settings/produits' },
            { title: 'Paramètres dépenses', href: '/settings/depenses' },
            { title: 'Paramètres ventes', href: '/settings/ventes' },
            { title: 'Modules metier', href: '/settings/modules' },
        );
    }

    return items;
});

const currentPath = typeof window !== undefined ? window.location.pathname : '';
</script>

<template>
    <div class="p-4 sm:p-6">
        <Heading
            title="Parametres"
            description="Gerez votre profil et les parametres de votre compte"
        />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full lg:w-48">
                <nav
                    class="flex gap-1 overflow-x-auto pb-2 sm:flex-col sm:space-y-1 sm:overflow-x-visible sm:pb-0"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'shrink-0 justify-start sm:w-full',
                            { 'bg-muted': urlIsActive(item.href, currentPath) },
                        ]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-4 lg:hidden" />

            <div :class="['flex-1', !wide && 'md:max-w-2xl']">
                <section :class="[!wide && 'max-w-xl', 'space-y-12']">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
