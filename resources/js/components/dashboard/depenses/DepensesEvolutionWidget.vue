<script setup lang="ts">
import { useChartTheme } from '@/composables/useChartTheme';
import type { TrancheDepenses } from '@/types/dashboard-depenses';
import Chart from 'primevue/chart';
import { computed, ref, watch } from 'vue';
import {
    CARTE_APOLLO,
    TITRE_GRAPHIQUE_APOLLO,
    montantCompact,
    montantGnf,
} from '../commissions/apollo';

// Même graphique que l'évolution de Stat-Commissions (RevenueOverviewWidget d'Apollo : barres de
// 12 px aux coins arrondis, primary-500 et primary-200, légende en bas, hauteur 300), en barres
// empilées : charges entreprise + avances récupérables = dépenses payées de la tranche. Une barre
// par jour ou par mois selon la période (découpage du serveur, TranchesPeriode).
const props = defineProps<{
    tranches: TrancheDepenses[];
}>();

const { getPrimary, getSurface, isDarkTheme } = useChartTheme();

const barData = ref({});
const barOptions = ref({});

const series = computed(() => ({
    labels: props.tranches.map((t) => t.label),
    charges: props.tranches.map((t) => t.charges),
    avances: props.tranches.map((t) => t.avances),
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

    barData.value = {
        labels: series.value.labels,
        datasets: [
            {
                label: 'Charges entreprise',
                backgroundColor:
                    documentStyle.getPropertyValue('--p-primary-500'),
                maxBarThickness: 12,
                borderRadius: 12,
                data: series.value.charges,
            },
            {
                label: 'Avances récupérables',
                backgroundColor:
                    documentStyle.getPropertyValue('--p-primary-200'),
                maxBarThickness: 12,
                borderRadius: 12,
                data: series.value.avances,
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
                mode: 'index',
                callbacks: {
                    label(context: {
                        dataset: { label: string };
                        parsed: { y: number };
                    }) {
                        return `${context.dataset.label} : ${montantGnf(context.parsed.y)}`;
                    },
                    footer(
                        elements: {
                            parsed: { y: number };
                        }[],
                    ) {
                        const payees = elements.reduce(
                            (somme, e) => somme + e.parsed.y,
                            0,
                        );

                        return `Dépenses payées : ${montantGnf(payees)}`;
                    },
                },
            },
        },
        scales: {
            x: {
                stacked: true,
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
                stacked: true,
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
                >Évolution des dépenses payées</span
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
