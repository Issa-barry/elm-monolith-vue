<script setup lang="ts">
import { useChartTheme } from '@/composables/useChartTheme';
import type { SiteStock } from '@/types/dashboard-stock';
import Chart from 'primevue/chart';
import { computed, ref, watch } from 'vue';
import { CARTE_APOLLO, TITRE_GRAPHIQUE_APOLLO } from '../commissions/apollo';
import { quantiteCompacte, quantiteTexte } from './quantites';

// Stock de l'article par agence, en barres horizontales empilées : disponible (primary-500) puis
// réservé (primary-200), dont la somme est le stock physique. Classées par le serveur du plus gros
// au plus petit stock physique ; une agence où l'article n'est pas disponible n'apparaît que si
// elle en a encore en stock, signalée « non disponible » dans l'infobulle.
const props = defineProps<{
    sites: SiteStock[];
    article: string | null;
}>();

const { getPrimary, getSurface, isDarkTheme } = useChartTheme();

// 32 px par agence, 160 px au moins. Au-delà de la hauteur du graphique voisin (entrées et
// sorties), la liste défile dans la carte au lieu de l'étirer : légende en tête, toujours visible
// à l'ouverture.
const hauteur = computed(() => Math.max(160, props.sites.length * 32 + 70));

const barData = ref({});
const barOptions = ref({});

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
        labels: props.sites.map((s) => s.label),
        datasets: [
            {
                label: 'Disponible',
                backgroundColor:
                    documentStyle.getPropertyValue('--p-primary-500'),
                maxBarThickness: 12,
                borderRadius: 12,
                data: props.sites.map((s) => s.disponible),
            },
            {
                label: 'Réservé',
                backgroundColor:
                    documentStyle.getPropertyValue('--p-primary-200'),
                maxBarThickness: 12,
                borderRadius: 12,
                data: props.sites.map((s) => s.reservee),
            },
        ],
    };

    barOptions.value = {
        indexAxis: 'y',
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
                position: 'top',
            },
            tooltip: {
                callbacks: {
                    label(context: {
                        dataIndex: number;
                        dataset: { label: string };
                        parsed: { x: number };
                    }) {
                        const site = props.sites[context.dataIndex];
                        const suffixe =
                            site && !site.disponible_sur_site
                                ? ' (article non disponible sur cette agence)'
                                : '';

                        return `${context.dataset.label} : ${quantiteTexte(context.parsed.x)}${suffixe}`;
                    },
                    footer(elements: { dataIndex: number }[]) {
                        const site = props.sites[elements[0]?.dataIndex ?? -1];

                        return site
                            ? `Physique : ${quantiteTexte(site.physique)}`
                            : '';
                    },
                },
            },
        },
        scales: {
            x: {
                stacked: true,
                ticks: {
                    color: textColorSecondary,
                    callback: (valeur: number) => quantiteCompacte(valeur),
                },
                grid: { color: surfaceBorder, drawBorder: false },
            },
            y: {
                stacked: true,
                ticks: { color: textColor, font: { weight: 600 } },
                grid: { display: false, drawBorder: false },
            },
        },
    };
}

watch(
    [getPrimary, getSurface, isDarkTheme, () => props.sites],
    () => initChart(),
    { immediate: true, deep: true },
);
</script>

<template>
    <div :class="CARTE_APOLLO">
        <div :class="TITRE_GRAPHIQUE_APOLLO" class="mb-7">
            Stock par agence
            <span
                class="text-[14px] font-normal text-[var(--p-text-muted-color)]"
                >· {{ article ?? '—' }}</span
            >
        </div>
        <div
            v-if="sites.length"
            class="max-h-[345px] overflow-y-auto"
            data-testid="sites-defilement"
        >
            <Chart
                type="bar"
                :data="barData"
                :height="hauteur"
                :options="barOptions"
            ></Chart>
        </div>
        <div
            v-else
            class="flex h-[160px] items-center justify-center text-center text-[var(--p-text-muted-color)]"
            data-testid="sites-vide"
        >
            Aucune agence ne gère cet article
        </div>
    </div>
</template>
