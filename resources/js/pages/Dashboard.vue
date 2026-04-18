<script setup lang="ts">
import HeaderWidget from '@/components/dashboard/banking/HeaderWidget.vue';
import MobileQuickMenu from '@/components/dashboard/banking/MobileQuickMenu.vue';
import StatsBankingWidget from '@/components/dashboard/banking/StatsBankingWidget.vue';
import CaParSiteDoughnutWidget from '@/components/dashboard/ventes/CaParSiteDoughnutWidget.vue';
import EvolutionCAWidget from '@/components/dashboard/ventes/EvolutionCAWidget.vue';
import PacksPieWidget from '@/components/dashboard/ventes/PacksPieWidget.vue';
import VehiculeCategoryWidget from '@/components/dashboard/ventes/VehiculeCategoryWidget.vue';
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

interface SiteData {
    nom: string;
    montant: number;
}

interface TypeVehiculeData {
    label: string;
    montant: number;
}

interface ProduitData {
    nom: string;
    total: number;
}

defineProps<{
    stats_factures: StatsFactures;
    evolution_mensuelle: MoisData[];
    evolution_quotidienne: JourData[];
    ca_par_site: SiteData[];
    ca_par_type_vehicule: TypeVehiculeData[];
    ca_par_produit: ProduitData[];
    periode: string;
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
            <HeaderWidget :periode="periode" />

            <div class="mt-4 grid grid-cols-12 gap-8">
                <StatsBankingWidget :stats="stats_factures" />
            </div>

            <div class="hidden sm:grid grid-cols-12 gap-8">
                <div class="col-span-12 xl:col-span-8">
                    <EvolutionCAWidget
                        :evolution-mensuelle="evolution_mensuelle"
                        :evolution-quotidienne="evolution_quotidienne"
                        :periode="periode"
                    />
                </div>
                <div class="col-span-12 xl:col-span-4">
                    <VehiculeCategoryWidget
                        :ca-par-type-vehicule="ca_par_type_vehicule"
                    />
                </div>
            </div>

            <div class="hidden sm:grid grid-cols-12 gap-8">
                <div class="col-span-12 xl:col-span-6">
                    <CaParSiteDoughnutWidget :ca-par-site="ca_par_site" />
                </div>
                <div class="col-span-12 xl:col-span-6">
                    <PacksPieWidget :ca-par-produit="ca_par_produit" />
                </div>
            </div>

            <MobileQuickMenu />
        </div>
    </AppLayout>
</template>
