<script setup lang="ts">
import HeaderWidget from '@/components/dashboard/banking/HeaderWidget.vue';
import MobileQuickMenu from '@/components/dashboard/banking/MobileQuickMenu.vue';
import StatsBankingWidget from '@/components/dashboard/banking/StatsBankingWidget.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Tableau de bord',
        href: dashboard().url,
    },
];
</script>

<template>
    <Head title="Tableau de bord" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4 sm:p-6">
            <HeaderWidget />

            <div class="mt-4 grid grid-cols-12 gap-8">
                <StatsBankingWidget />
            </div>

            <MobileQuickMenu />
        </div>
    </AppLayout>
</template>
