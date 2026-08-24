<script setup lang="ts">
defineProps<{ wide?: boolean }>();

import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { usePermissions } from '@/composables/usePermissions';
import { toUrl, urlIsActive } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editOrganisation } from '@/routes/organisation';
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

interface SidebarNavGroup {
    title: string;
    items: NavItem[];
}

const sidebarNavGroups = computed((): SidebarNavGroup[] => {
    const groups: SidebarNavGroup[] = [
        {
            title: 'Mon compte',
            items: [
                { title: 'Profil', href: editProfile() },
                { title: 'Mot de passe', href: editPassword() },
                { title: 'Double authentification', href: show() },
                { title: 'Apparence', href: editAppearance() },
            ],
        },
    ];

    if (isAdmin.value) {
        groups.push({
            title: 'Organisation',
            items: [
                {
                    title: "Informations de l'organisation",
                    href: editOrganisation(),
                },
                { title: 'Rôles et permissions', href: '/backoffice/roles' },
            ],
        });
    }

    if (can('parametres.update')) {
        groups.push(
            {
                title: 'Gestion',
                items: [
                    { title: 'Produits', href: '/settings/produits' },
                    { title: 'Dépenses', href: '/settings/depenses' },
                    { title: 'Ventes', href: '/settings/ventes' },
                    { title: 'Commissions', href: '/settings/commissions' },
                    { title: 'Modules', href: '/settings/modules' },
                ],
            },
            {
                title: 'Administration',
                items: [
                    {
                        title: 'Imports et modèles',
                        href: editParametres().url,
                    },
                ],
            },
        );
    }

    return groups;
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
                    <div
                        v-for="group in sidebarNavGroups"
                        :key="group.title"
                        class="flex shrink-0 gap-1 sm:mt-3 sm:flex-col sm:gap-0.5"
                    >
                        <p
                            class="hidden px-2 pb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase sm:block"
                        >
                            {{ group.title }}
                        </p>
                        <Button
                            v-for="item in group.items"
                            :key="toUrl(item.href)"
                            variant="ghost"
                            :class="[
                                'shrink-0 justify-start sm:w-full',
                                {
                                    'bg-muted': urlIsActive(
                                        item.href,
                                        currentPath,
                                    ),
                                },
                            ]"
                            as-child
                        >
                            <Link :href="item.href">
                                <component :is="item.icon" class="h-4 w-4" />
                                {{ item.title }}
                            </Link>
                        </Button>
                    </div>
                </nav>
            </aside>

            <Separator class="my-4 lg:hidden" />

            <div :class="['min-w-0 flex-1', !wide && 'md:max-w-2xl']">
                <section
                    :class="[
                        !wide && 'max-w-xl',
                        'max-w-full min-w-0 space-y-12',
                    ]"
                >
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
