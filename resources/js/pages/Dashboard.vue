<script setup lang="ts">
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
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Tableau de bord</h1>
                <p class="mt-1 text-sm text-muted-foreground">Bienvenue sur votre espace de gestion.</p>
            </div>
        </div>
    </AppLayout>
</template>
