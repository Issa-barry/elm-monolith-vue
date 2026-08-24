<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import ThemeSettings from '@/pages/settings/partials/ThemeSettings.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Paramètres', href: '/settings/profile' },
    { title: 'Apparence', href: '/settings/appearance' },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Apparence" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Apparence de l'organisation"
                    description="Ces choix s'appliquent à tous les utilisateurs."
                />
                <ThemeSettings />
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
