<script setup lang="ts">
import { useChartTheme } from '@/composables/useChartTheme';
import {
    partEnPourcentage as pourcentage,
    totalParts,
} from '@/lib/pourcentages';
import type { PartDepenses } from '@/types/dashboard-depenses';
import Chart from 'primevue/chart';
import { computed, ref, watch } from 'vue';
import {
    CARTE_APOLLO,
    TITRE_GRAPHIQUE_APOLLO,
    montantCompact,
    montantGnf,
} from '../commissions/apollo';

// Comparaison des agences : dépenses validées par agence de saisie, classées par le serveur du plus
// gros au plus petit, en barres horizontales dans le style des barres d'Apollo (12 px, coins
// arrondis, primary-500). Montant et pourcentage des dépenses payées écrits au bout de chaque barre
// et repris dans l'infobulle.
const props = defineProps<{
    agences: PartDepenses[];
}>();

const { getPrimary, getSurface, isDarkTheme } = useChartTheme();

// Hauteur selon le nombre d'agences : 44 px par barre, 160 px au moins.
const hauteur = computed(() => Math.max(160, props.agences.length * 44 + 40));

const barData = ref({});
const barOptions = ref({});
let couleurEtiquette = '';

function etiquette(montant: number): string {
    return `${montantCompact(montant)} · ${pourcentage(montant, totalParts(props.agences))}`;
}

// Montant et pourcentage écrits juste après chaque barre.
const valeursAuBoutDesBarres = {
    id: 'valeursAuBoutDesBarresDepenses',
    afterDatasetsDraw(chart: {
        ctx: CanvasRenderingContext2D;
        getDatasetMeta: (i: number) => {
            data: { x: number; y: number }[];
        };
    }) {
        const { ctx } = chart;
        ctx.save();
        ctx.font = '600 12px Poppins, sans-serif';
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = couleurEtiquette;
        chart.getDatasetMeta(0).data.forEach((barre, i) => {
            const montant = props.agences[i]?.montant ?? 0;
            ctx.fillText(etiquette(montant), barre.x + 8, barre.y);
        });
        ctx.restore();
    },
};

function initChart() {
    const documentStyle = getComputedStyle(document.documentElement);
    const textColor = documentStyle.getPropertyValue('--p-text-color');
    const textColorSecondary = documentStyle.getPropertyValue(
        '--p-text-muted-color',
    );
    const surfaceBorder = documentStyle.getPropertyValue(
        '--p-content-border-color',
    );
    const total = totalParts(props.agences);
    couleurEtiquette = textColor;

    barData.value = {
        labels: props.agences.map((a) => a.label),
        datasets: [
            {
                label: 'Dépenses payées',
                backgroundColor:
                    documentStyle.getPropertyValue('--p-primary-500'),
                maxBarThickness: 12,
                borderRadius: 12,
                data: props.agences.map((a) => a.montant),
            },
        ],
    };

    // Marge à droite de l'axe : place pour l'étiquette de la plus longue barre.
    const max = Math.max(0, ...props.agences.map((a) => a.montant));

    barOptions.value = {
        indexAxis: 'y',
        animation: {
            duration: 0,
        },
        responsive: true,
        maintainAspectRatio: false,
        layout: {
            padding: { right: 110 },
        },
        plugins: {
            legend: {
                display: false,
            },
            tooltip: {
                callbacks: {
                    label(context: { parsed: { x: number } }) {
                        return `Dépenses payées : ${montantGnf(context.parsed.x)} (${pourcentage(context.parsed.x, total)})`;
                    },
                },
            },
        },
        scales: {
            x: {
                beginAtZero: true,
                suggestedMax: max,
                ticks: {
                    color: textColorSecondary,
                    callback: (valeur: number) => montantCompact(valeur),
                },
                grid: {
                    color: surfaceBorder,
                    drawBorder: false,
                },
            },
            y: {
                ticks: {
                    color: textColor,
                    font: {
                        weight: 600,
                    },
                },
                grid: {
                    display: false,
                    drawBorder: false,
                },
            },
        },
    };
}

watch(
    [getPrimary, getSurface, isDarkTheme, () => props.agences],
    () => {
        initChart();
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <div :class="CARTE_APOLLO">
        <div :class="TITRE_GRAPHIQUE_APOLLO" class="mb-7">
            Comparaison des agences
        </div>
        <Chart
            v-if="agences.length"
            type="bar"
            :data="barData"
            :height="hauteur"
            :options="barOptions"
            :plugins="[valeursAuBoutDesBarres]"
        ></Chart>
        <div
            v-else
            class="flex h-[160px] items-center justify-center text-center text-[var(--p-text-muted-color)]"
            data-testid="agences-vide"
        >
            Aucune dépense payée sur la période
        </div>
    </div>
</template>
