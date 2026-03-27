<script setup lang="ts">
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

const { can } = usePermissions();

const sidebarNavItems = computed((): NavItem[] => {
    const items: NavItem[] = [
        { title: 'Profil',                href: editProfile() },
        { title: 'Mot de passe',          href: editPassword() },
        { title: 'Double authentification', href: show() },
        { title: 'Apparence',             href: editAppearance() },
    ];

    if (can('users.read')) {
        items.push({ title: 'Rôles & Permissions', href: '/roles' });
    }

    if (can('parametres.update')) {
        items.push({ title: 'Paramétrage système', href: editParametres().url });
    }

    return items;
});

const currentPath = typeof window !== undefined ? window.location.pathname : '';
</script>

<template>
    <div class="p-4 sm:p-6">
        <Heading
            title="Paramètres"
            description="Gérez votre profil et les paramètres de votre compte"
        />

        <div class="flex flex-col lg:flex-row lg:space-x-12">
            <aside class="w-full lg:w-48">
                <nav class="flex overflow-x-auto gap-1 sm:flex-col sm:space-y-1 sm:overflow-x-visible pb-2 sm:pb-0">
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

            <div class="flex-1 md:max-w-2xl">
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
