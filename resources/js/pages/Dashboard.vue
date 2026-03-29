<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import HeaderWidget from '@/components/dashboard/banking/HeaderWidget.vue';
import StatsBankingWidget from '@/components/dashboard/banking/StatsBankingWidget.vue';
import MobileQuickMenu from '@/components/dashboard/banking/MobileQuickMenu.vue';

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

            <div class="grid grid-cols-12 gap-8 mt-4">
                 <StatsBankingWidget />
            </div>

            <MobileQuickMenu />
         </div>

         
    </AppLayout>
</template>
 
