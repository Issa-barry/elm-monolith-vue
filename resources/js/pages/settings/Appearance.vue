<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import AppearanceTabs from '@/components/AppearanceTabs.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { usePermissions } from '@/composables/usePermissions';
import { type BreadcrumbItem } from '@/types';

import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import ThemeSettings from '@/pages/settings/partials/ThemeSettings.vue';
import { edit } from '@/routes/appearance';

const { can } = usePermissions();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Apparence',
        href: edit().url,
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Apparence" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Apparence"
                    description="Choisissez le mode d'affichage de votre compte."
                />
                <AppearanceTabs />

                <section v-if="can('parametres.update')" class="space-y-3 pt-4">
                    <HeadingSmall
                        title="Apparence de l'organisation"
                        description="Ces choix s'appliquent à tous les utilisateurs."
                    />
                    <ThemeSettings />
                </section>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
