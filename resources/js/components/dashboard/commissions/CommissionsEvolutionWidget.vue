<script setup lang="ts">
import { useChartTheme } from '@/composables/useChartTheme';
import { commissions } from '@/routes/dashboard';
import type { TrancheCommissions } from '@/types/dashboard-commissions';
import type { PeriodeOption } from '@/types/periode';
import { router } from '@inertiajs/vue3';
import Chart from 'primevue/chart';
import Select from 'primevue/select';
import { computed, ref, watch } from 'vue';
import {
    CARTE_APOLLO,
    TITRE_GRAPHIQUE_APOLLO,
    montantCompact,
    montantGnf,
} from './apollo';

// Port de RevenueOverviewWidget.vue (Apollo) : barres de 12 px aux coins arrondis, primary-500 et
// primary-200, légende en bas, hauteur 300. Les séries Revenue/Profit deviennent Générées/Payées,
// une barre par jour ou par mois selon la période (découpage fait par le serveur).
//
// Le sélecteur d'Apollo (« Last Week / This Week ») devient le choix de période de la page : il
// recharge les données du serveur (cartes, graphique, répartition dépendent toutes de la période).
const props = defineProps<{
    tranches: TrancheCommissions[];
    periode: string;
    periodes: PeriodeOption[];
}>();

const { getPrimary, getSurface, isDarkTheme } = useChartTheme();

const periodeChoisie = ref<PeriodeOption | undefined>(
    props.periodes.find((p) => p.value === props.periode),
);
const chargement = ref(false);

watch(
    () => props.periode,
    (valeur) => {
        periodeChoisie.value = props.periodes.find((p) => p.value === valeur);
    },
);

function changerPeriode() {
    if (!periodeChoisie.value) return;

    router.get(
        commissions().url,
        { periode: periodeChoisie.value.value },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (chargement.value = true),
            onFinish: () => (chargement.value = false),
        },
    );
}

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
        <div class="mb-[42px] flex items-start justify-between gap-4">
            <span :class="TITRE_GRAPHIQUE_APOLLO"
                >Évolution des commissions</span
            >
            <!-- Select `w-40` d'Apollo : 140 px, champ 7 × 10,5 px, flèche 35 px (base 14 px). -->
            <Select
                v-model="periodeChoisie"
                :options="periodes"
                option-label="label"
                :loading="chargement"
                class="w-[140px] shrink-0 [&_.p-select-dropdown]:w-[35px]! [&_.p-select-label]:px-[10.5px]! [&_.p-select-label]:py-[7px]!"
                aria-label="Période"
                data-testid="periode-commissions"
                @change="changerPeriode"
            />
        </div>
        <Chart
            type="bar"
            :height="300"
            :data="barData"
            :options="barOptions"
        ></Chart>
    </div>
</template>
