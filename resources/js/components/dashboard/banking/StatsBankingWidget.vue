<script setup lang="ts">
import KpiCardsResponsive from '@/components/dashboard/shared/KpiCardsResponsive.vue';
import type { KpiWidgetItem } from '@/types/kpi-widgets';
import { computed } from 'vue';

interface StatsFactures {
    total_count: number;
    total_montant: number;
    payees_count: number;
    payees_montant: number;
    total_encaisse: number;
    impayees_count: number;
    annulees_count: number;
    reste_a_encaisser: number;
}

const props = defineProps<{ stats: StatsFactures }>();

function formatGNF(value: number): string {
    return `${new Intl.NumberFormat('fr-FR').format(value ?? 0)} GNF`;
}

const kpiItems = computed<KpiWidgetItem[]>(() => [
    {
        id: 'factures-total',
        title: 'Total Factures',
        value: formatGNF(props.stats.total_montant),
        subtitle: `${props.stats.total_count} facture${props.stats.total_count > 1 ? 's' : ''}`,
        variant: 'primary-wave',
        align: 'left',
        desktopClass: 'col-span-12 md:col-span-6 xl:col-span-4',
    },
    {
        id: 'factures-payees',
        title: 'Déjà encaissé',
        value: formatGNF(props.stats.total_encaisse),
        subtitle: `${props.stats.payees_count} facture${props.stats.payees_count > 1 ? 's' : ''} payee${props.stats.payees_count > 1 ? 's' : ''} + acomptes`,
        align: 'center',
        desktopClass: 'col-span-12 md:col-span-6 xl:col-span-4',
    },
    {
        id: 'reste-encaisser',
        title: 'Reste a encaisser',
        value: formatGNF(props.stats.reste_a_encaisser),
        subtitle: `${props.stats.impayees_count} impayee${props.stats.impayees_count > 1 ? 's' : ''} - ${props.stats.annulees_count} annulee${props.stats.annulees_count > 1 ? 's' : ''}`,
        align: 'center',
        desktopClass: 'col-span-12 md:col-span-6 xl:col-span-4',
    },
]);
</script>

<template>
    <KpiCardsResponsive
        :items="kpiItems"
        breakpoint="sm"
        desktop-wrapper-class="grid grid-cols-12 gap-8"
        desktop-item-default-class="col-span-12 md:col-span-6 xl:col-span-4"
    />
</template>
