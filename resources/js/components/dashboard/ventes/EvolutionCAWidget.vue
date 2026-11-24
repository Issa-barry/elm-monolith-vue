<script setup lang="ts">
import { useChartTheme } from '@/composables/useChartTheme';
import Chart from 'primevue/chart';
import { computed, onMounted, ref, watch } from 'vue';

// ── Props ─────────────────────────────────────────────────────────────────────
// Tranches calculées par le serveur sur la période du filtre de la page (TranchesPeriode) : une
// barre par jour jusqu'à 31 jours, par mois au-delà. Le graphique n'a pas de période propre et ne
// lit jamais l'horloge du navigateur (ADR 0057).
interface TrancheVentes {
    cle: string;
    label: string;
    payees: number;
    partielles: number;
    impayees: number;
}

const props = defineProps<{
    evolution: {
        granularite: 'jour' | 'mois';
        tranches: TrancheVentes[];
    };
}>();

// ── Thème — même pattern que ChartDoc.vue (useLayout remplacé par useChartTheme)
const { getPrimary, getSurface, isDarkTheme } = useChartTheme();

// ── Chart refs — nommage identique à ChartDoc ─────────────────────────────────
const barData = ref({});
const barOptions = ref({});

const series = computed(() => ({
    labels: props.evolution.tranches.map((t) => t.label),
    payees: props.evolution.tranches.map((t) => t.payees),
    partielles: props.evolution.tranches.map((t) => t.partielles),
    impayees: props.evolution.tranches.map((t) => t.impayees),
}));

// ── setColorOptions ── copie exacte de RevenueOverviewWidget.vue (Apollo) ─────
function setColorOptions() {
    const documentStyle = getComputedStyle(document.documentElement);
    const textColor =
        documentStyle.getPropertyValue('--p-text-color').trim() ||
        (isDarkTheme.value ? '#e2e8f0' : '#0f172a');
    const textColorSecondary =
        documentStyle.getPropertyValue('--p-text-muted-color').trim() ||
        (isDarkTheme.value ? '#94a3b8' : '#64748b');
    const surfaceBorder =
        documentStyle.getPropertyValue('--p-content-border-color').trim() ||
        (isDarkTheme.value ? '#334155' : '#e2e8f0');

    const data = series.value;

    barData.value = {
        labels: data.labels,
        datasets: [
            {
                label: 'Payées',
                backgroundColor:
                    documentStyle.getPropertyValue('--p-green-500'),
                borderColor: documentStyle.getPropertyValue('--p-green-500'),
                barThickness: 12,
                borderRadius: 12,
                data: data.payees,
            },
            {
                label: 'Partielles',
                backgroundColor:
                    documentStyle.getPropertyValue('--p-orange-500'),
                borderColor: documentStyle.getPropertyValue('--p-orange-500'),
                barThickness: 12,
                borderRadius: 12,
                data: data.partielles,
            },
            {
                label: 'Impayées',
                backgroundColor: documentStyle.getPropertyValue('--p-red-500'),
                borderColor: documentStyle.getPropertyValue('--p-red-500'),
                barThickness: 12,
                borderRadius: 12,
                data: data.impayees,
            },
        ],
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
                    padding: 28,
                },
                position: 'bottom',
            },
            tooltip: {
                callbacks: {
                    label(ctx: {
                        dataset: { label: string };
                        parsed: { y: number };
                    }) {
                        return ` ${ctx.dataset.label} : ${new Intl.NumberFormat('fr-FR').format(ctx.parsed.y)} GNF`;
                    },
                },
            },
        },
        scales: {
            x: {
                ticks: {
                    color: textColorSecondary,
                    font: { weight: 500 },
                },
                grid: {
                    display: false,
                    drawBorder: false,
                },
            },
            y: {
                ticks: {
                    color: textColorSecondary,
                    callback: (value: number) =>
                        new Intl.NumberFormat('fr-FR', {
                            notation: 'compact',
                            maximumFractionDigits: 1,
                        }).format(value),
                },
                grid: {
                    color: surfaceBorder,
                    drawBorder: false,
                },
            },
        },
    };
}

// Pattern ChartDoc.vue exact
onMounted(() => {
    setColorOptions();
});

watch(
    [getPrimary, getSurface, isDarkTheme, series],
    () => {
        setColorOptions();
    },
    { immediate: true },
);
</script>

<template>
    <div class="card">
        <!-- Hauteur de l'ancien sélecteur conservée : la carte garde ses dimensions. -->
        <div class="mb-6 flex min-h-10 items-start justify-between">
            <span
                class="text-surface-900 dark:text-surface-0 text-xl font-semibold"
            >
                Évolution CA Payées vs Impayées
            </span>
        </div>

        <!-- class="h-[24rem]" — pattern ChartDoc, pas de :height prop -->
        <Chart
            type="bar"
            :data="barData"
            :options="barOptions"
            class="h-[24rem]"
        />
    </div>
</template>
