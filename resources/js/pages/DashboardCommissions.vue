<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { HandCoins } from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tableau de bord', href: dashboard().url },
    { title: 'Commissions', href: '/backoffice/tableau-de-bord/commissions' },
];
</script>

<template>
    <Head title="Tableau de bord — Commissions" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 p-4 sm:p-6">
            <div>
                <h1 class="text-2xl font-semibold">Commissions</h1>
                <p class="text-sm text-muted-foreground">
                    Tableau de bord des commissions.
                </p>
            </div>

            <div
                class="card flex flex-col items-center justify-center gap-3 py-16 text-center"
            >
                <HandCoins class="size-10 text-muted-foreground" />
                <p class="font-medium">Tableau de bord en préparation</p>
                <p class="max-w-md text-sm text-muted-foreground">
                    Les indicateurs des commissions seront affichés ici.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
