<script setup lang="ts">
import { useChartTheme } from '@/composables/useChartTheme';
import type { EvolutionVehicules } from '@/types/dashboard-vehicules';
import Chart from 'primevue/chart';
import { computed, ref, watch } from 'vue';
import { CARTE_APOLLO, TITRE_GRAPHIQUE_APOLLO } from '../commissions/apollo';
import { nombreTexte } from './nombres';

// Rotations par jour ou par mois (découpage du serveur, TranchesPeriode), barres empilées par type
// d'opération dans le style des barres d'Apollo (12 px, coins arrondis), nuances de la couleur
// primaire. Une rotation ne compte qu'une fois, dans la série de son opération.
const props = defineProps<{
    evolution: EvolutionVehicules;
}>();

const { getPrimary, getSurface, isDarkTheme } = useChartTheme();

// Nuances espacées : quatre séries au plus, lisibles côte à côte en clair comme en sombre.
const NUANCES = ['600', '300', '800', '400'];

const barData = ref({});
const barOptions = ref({});

const vide = computed(() =>
    props.evolution.series.every((s) => s.valeurs.every((v) => v === 0)),
);

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
        labels: props.evolution.tranches.map((t) => t.label),
        datasets: props.evolution.series.map((serie, index) => ({
            label: serie.label,
            stack: 'rotations',
            backgroundColor: documentStyle.getPropertyValue(
                `--p-primary-${NUANCES[index % NUANCES.length]}`,
            ),
            maxBarThickness: 12,
            borderRadius: 12,
            data: serie.valeurs,
        })),
    };

    barOptions.value = {
        animation: { duration: 0 },
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                labels: {
                    color: textColor,
                    usePointStyle: true,
                    font: { weight: 700 },
                    padding: 20,
                },
                position: 'bottom',
            },
            tooltip: {
                callbacks: {
                    label(context: {
                        dataset: { label: string };
                        parsed: { y: number };
                    }) {
                        return `${context.dataset.label} : ${nombreTexte(context.parsed.y)}`;
                    },
                },
            },
        },
        scales: {
            x: {
                stacked: true,
                ticks: { color: textColorSecondary, font: { weight: 500 } },
                grid: { display: false, drawBorder: false },
            },
            y: {
                stacked: true,
                ticks: {
                    color: textColorSecondary,
                    precision: 0,
                },
                grid: { color: surfaceBorder, drawBorder: false },
            },
        },
    };
}

watch(
    [getPrimary, getSurface, isDarkTheme, () => props.evolution],
    () => initChart(),
    { immediate: true, deep: true },
);
</script>

<template>
    <div :class="CARTE_APOLLO">
        <div
            class="mb-[42px] flex min-h-[33px] items-start justify-between gap-4"
        >
            <span :class="TITRE_GRAPHIQUE_APOLLO"
                >Rotations
                <span
                    class="text-[14px] font-normal text-[var(--p-text-muted-color)]"
                    >· par {{ evolution.granularite }}</span
                ></span
            >
        </div>
        <Chart
            v-if="!vide"
            type="bar"
            :height="300"
            :data="barData"
            :options="barOptions"
        ></Chart>
        <div
            v-else
            class="flex h-[300px] items-center justify-center text-center text-[var(--p-text-muted-color)]"
            data-testid="rotations-vide"
        >
            Aucune rotation sur la période
        </div>
    </div>
</template>
