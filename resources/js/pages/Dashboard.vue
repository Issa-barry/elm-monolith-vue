<script setup lang="ts">
import HeaderWidget from '@/components/dashboard/banking/HeaderWidget.vue';
import MobileQuickMenu from '@/components/dashboard/banking/MobileQuickMenu.vue';
import StatsBankingWidget from '@/components/dashboard/banking/StatsBankingWidget.vue';
import EvolutionCAWidget from '@/components/dashboard/ventes/EvolutionCAWidget.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

interface StatsFactures {
    total_count: number;
    total_montant: number;
    payees_count: number;
    payees_montant: number;
    impayees_count: number;
    annulees_count: number;
    reste_a_encaisser: number;
}

interface MoisData {
    payees: number;
    partielles: number;
    impayees: number;
}

interface JourData {
    date: string;
    payees: number;
    partielles: number;
    impayees: number;
}

defineProps<{
    stats_factures: StatsFactures;
    evolution_mensuelle: MoisData[];
    evolution_quotidienne: JourData[];
}>();

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
                <StatsBankingWidget :stats="stats_factures" />
            </div>

            <div class="grid grid-cols-12 gap-8">
                <div class="col-span-12">
                    <EvolutionCAWidget
                        :evolution-mensuelle="evolution_mensuelle"
                        :evolution-quotidienne="evolution_quotidienne"
                    />
                </div>
            </div>

            <MobileQuickMenu />
        </div>
    </AppLayout>
</template>
