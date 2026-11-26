<script setup lang="ts">
import { useChartTheme } from '@/composables/useChartTheme';
import type { EvolutionStock } from '@/types/dashboard-stock';
import Chart from 'primevue/chart';
import { computed, ref, watch } from 'vue';
import { CARTE_APOLLO, TITRE_GRAPHIQUE_APOLLO } from '../commissions/apollo';
import { quantiteCompacte, quantiteTexte } from './quantites';

// Entrées et sorties de l'article par jour ou par mois (découpage du serveur, TranchesPeriode),
// barres empilées par motif dans le style des barres d'Apollo (12 px, coins arrondis) : les entrées
// au-dessus de zéro dans les nuances de la couleur primaire, les sorties en dessous dans les
// nuances de rose. Une seule unité : celle de l'article choisi.
const props = defineProps<{
    evolution: EvolutionStock;
    article: string | null;
}>();

const { getPrimary, getSurface, isDarkTheme } = useChartTheme();

const NUANCES_ENTREES = ['500', '300', '700', '200', '600', '400'];
const NUANCES_SORTIES = ['500', '300', '700', '200', '600', '400'];

const barData = ref({});
const barOptions = ref({});

const vide = computed(() => props.evolution.series.length === 0);

function initChart() {
    const documentStyle = getComputedStyle(document.documentElement);
    const textColor = documentStyle.getPropertyValue('--p-text-color');
    const textColorSecondary = documentStyle.getPropertyValue(
        '--p-text-muted-color',
    );
    const surfaceBorder = documentStyle.getPropertyValue(
        '--p-content-border-color',
    );
    let entrees = 0;
    let sorties = 0;

    barData.value = {
        labels: props.evolution.tranches.map((t) => t.label),
        datasets: props.evolution.series.map((serie) => {
            const entree = serie.sens === 'entree';
            const nuance = entree
                ? NUANCES_ENTREES[entrees++ % NUANCES_ENTREES.length]
                : NUANCES_SORTIES[sorties++ % NUANCES_SORTIES.length];

            return {
                label: `${entree ? 'Entrée' : 'Sortie'} · ${serie.label}`,
                stack: 'flux',
                backgroundColor: documentStyle.getPropertyValue(
                    entree ? `--p-primary-${nuance}` : `--p-pink-${nuance}`,
                ),
                maxBarThickness: 12,
                borderRadius: 12,
                data: serie.valeurs,
            };
        }),
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
                        return `${context.dataset.label} : ${quantiteTexte(Math.abs(context.parsed.y))}`;
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
                    callback: (valeur: number) => quantiteCompacte(valeur),
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
                >Entrées et sorties
                <span
                    class="text-[14px] font-normal text-[var(--p-text-muted-color)]"
                    >· {{ article ?? '—' }}</span
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
            data-testid="flux-vide"
        >
            Aucun mouvement de stock sur la période
        </div>
    </div>
</template>
