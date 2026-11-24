<script setup lang="ts">
import { useChartTheme } from '@/composables/useChartTheme';
import type { TrancheCommissions } from '@/types/dashboard-commissions';
import Chart from 'primevue/chart';
import { computed, ref, watch } from 'vue';
import {
    CARTE_APOLLO,
    TITRE_GRAPHIQUE_APOLLO,
    montantCompact,
    montantGnf,
} from './apollo';

// Port de RevenueOverviewWidget.vue (Apollo) : barres de 12 px aux coins arrondis, primary-500 et
// primary-200, légende en bas, hauteur 300. Les séries Revenue/Profit deviennent Générées/Payées,
// une barre par jour ou par mois selon la période (découpage fait par le serveur, TranchesPeriode).
//
// Le graphique n'a pas de période propre : il suit le filtre unique de la page (SelecteurPeriode).
// Le sélecteur d'Apollo (« Last Week / This Week ») est retiré, sa hauteur rendue (33 px) est conservée.
const props = defineProps<{
    tranches: TrancheCommissions[];
}>();

const { getPrimary, getSurface, isDarkTheme } = useChartTheme();

const barData = ref({});
const barOptions = ref({});

const series = computed(() => ({
    labels: props.tranches.map((t) => t.label),
    generees: props.tranches.map((t) => t.total_genere),
    payees: props.tranches.map((t) => t.deja_paye),
}));

function initChart() {
    const documentStyle = getComputedStyle(document.documentElement);
    const textColor = documentStyle.getPropertyValue('--p-text-color');
    const textColorSecondary = documentStyle.getPropertyValue(
        '--p-text-muted-color',
    );
    const surfaceBorder = documentStyle.getPropertyValue(
        '--p-content-border-color',
    );

    // Barres de 12 px comme Apollo (`barThickness: 12`), mais en épaisseur maximale : identique dès
    // que la place le permet (bureau), plus fines quand 31 jours tiennent dans un écran de téléphone
    // au lieu de se chevaucher.
    barData.value = {
        labels: series.value.labels,
        datasets: [
            {
                label: 'Générées',
                backgroundColor:
                    documentStyle.getPropertyValue('--p-primary-500'),
                maxBarThickness: 12,
                borderRadius: 12,
                data: series.value.generees,
            },
            {
                label: 'Payées',
                backgroundColor:
                    documentStyle.getPropertyValue('--p-primary-200'),
                maxBarThickness: 12,
                borderRadius: 12,
                data: series.value.payees,
            },
        ],
    };

    barOptions.value = {
        animation: {
            duration: 0,
        },
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                labels: {
                    color: textColor,
                    usePointStyle: true,
                    font: {
                        weight: 700,
                    },
                    padding: 28,
                },
                position: 'bottom',
            },
            tooltip: {
                callbacks: {
                    label(context: {
                        dataset: { label: string };
                        parsed: { y: number };
                    }) {
                        return `${context.dataset.label} : ${montantGnf(context.parsed.y)}`;
                    },
                },
            },
        },
        scales: {
            x: {
                ticks: {
                    color: textColorSecondary,
                    font: {
                        weight: 500,
                    },
                },
                grid: {
                    display: false,
                    drawBorder: false,
                },
            },
            y: {
                ticks: {
                    color: textColorSecondary,
                    callback: (valeur: number) => montantCompact(valeur),
                },
                grid: {
                    color: surfaceBorder,
                    drawBorder: false,
                },
            },
        },
    };
}

watch(
    [getPrimary, getSurface, isDarkTheme, series],
    () => {
        initChart();
    },
    { immediate: true },
);
</script>

<template>
    <div :class="CARTE_APOLLO">
        <div
            class="mb-[42px] flex min-h-[33px] items-start justify-between gap-4"
        >
            <span :class="TITRE_GRAPHIQUE_APOLLO"
                >Évolution des commissions</span
            >
        </div>
        <Chart
            type="bar"
            :height="300"
            :data="barData"
            :options="barOptions"
        ></Chart>
    </div>
</template>
