<script setup lang="ts">
import { useChartTheme } from '@/composables/useChartTheme';
import { partEnPourcentage, totalParts } from '@/lib/pourcentages';
import type { RepartitionCommission } from '@/types/dashboard-commissions';
import Chart from 'primevue/chart';
import { ref, watch } from 'vue';
import { CARTE_APOLLO, TITRE_GRAPHIQUE_APOLLO, montantGnf } from './apollo';

// Port de SalesByCategoryWidget.vue (Apollo) : camembert de hauteur 300, nuances de la couleur
// primaire, légende en bas. Les catégories de produits deviennent les types de bénéficiaires
// (livreurs, propriétaires, sites, consultants, parrains), classés par le serveur.
const props = defineProps<{
    repartition: RepartitionCommission[];
}>();

const { getPrimary, getSurface, isDarkTheme } = useChartTheme();

// Trois premières nuances = celles d'Apollo (700, 400, 100, survol 600, 300, 200) ; les types
// suivants prennent les nuances intermédiaires de la même palette.
const NUANCES = ['700', '400', '100', '500', '200'];
const NUANCES_SURVOL = ['600', '300', '200', '400', '300'];

// Nuances foncées : pourcentage écrit en blanc ; les autres en primaire 900, pour rester lisible.
const NUANCES_FONCEES = ['700', '500'];
// En dessous de 5 % du total, la part est trop étroite pour y écrire : le pourcentage reste
// dans la légende et l'infobulle.
const PART_MIN_ETIQUETTE = 0.05;

const pieData = ref({});
const pieOptions = ref({});
let couleursEtiquettes: string[] = [];

function totalRepartition(): number {
    return totalParts(props.repartition);
}

// Part du total, une décimale (lib/pourcentages) : « 45,3 % », « < 0,1 % » pour une part infime.
const pourcentage = partEnPourcentage;

// Pourcentage écrit au centre de chaque part assez large (Chart.js n'a pas d'étiquettes natives).
const pourcentagesSurParts = {
    id: 'pourcentagesSurParts',
    afterDatasetsDraw(chart: {
        ctx: CanvasRenderingContext2D;
        getDatasetMeta: (i: number) => {
            data: {
                tooltipPosition: (final: boolean) => { x: number; y: number };
            }[];
        };
        getDataVisibility: (i: number) => boolean;
    }) {
        const total = totalRepartition();
        if (total <= 0) {
            return;
        }
        const { ctx } = chart;
        ctx.save();
        ctx.font = '700 13px Poppins, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        chart.getDatasetMeta(0).data.forEach((arc, i) => {
            const montant = props.repartition[i]?.montant ?? 0;
            if (
                !chart.getDataVisibility(i) ||
                montant / total < PART_MIN_ETIQUETTE
            ) {
                return;
            }
            const { x, y } = arc.tooltipPosition(true);
            ctx.fillStyle = couleursEtiquettes[i] ?? '#ffffff';
            ctx.fillText(pourcentage(montant, total), x, y);
        });
        ctx.restore();
    },
};

function initChart() {
    const documentStyle = getComputedStyle(document.documentElement);
    const textColor = documentStyle.getPropertyValue('--p-text-color');
    const nuance = (n: string) =>
        documentStyle.getPropertyValue(`--p-primary-${n}`);
    const total = totalRepartition();

    couleursEtiquettes = props.repartition.map((_, i) =>
        NUANCES_FONCEES.includes(NUANCES[i % NUANCES.length])
            ? '#ffffff'
            : nuance('900'),
    );

    pieData.value = {
        labels: props.repartition.map(
            (r) => `${r.label} (${pourcentage(r.montant, total)})`,
        ),
        datasets: [
            {
                data: props.repartition.map((r) => r.montant),
                backgroundColor: props.repartition.map((_, i) =>
                    nuance(NUANCES[i % NUANCES.length]),
                ),
                hoverBackgroundColor: props.repartition.map((_, i) =>
                    nuance(NUANCES_SURVOL[i % NUANCES_SURVOL.length]),
                ),
            },
        ],
    };
    pieOptions.value = {
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
                    label(context: { dataIndex: number; parsed: number }) {
                        const libelle =
                            props.repartition[context.dataIndex]?.label ?? '';

                        return `${libelle} : ${montantGnf(context.parsed)} (${pourcentage(context.parsed, total)})`;
                    },
                },
            },
        },
    };
}

watch(
    [getPrimary, getSurface, isDarkTheme, () => props.repartition],
    () => {
        initChart();
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <div :class="CARTE_APOLLO">
        <div :class="TITRE_GRAPHIQUE_APOLLO" class="mb-[42px]">
            Répartition des commissions
        </div>
        <Chart
            v-if="repartition.length"
            type="pie"
            :data="pieData"
            :height="300"
            :options="pieOptions"
            :plugins="[pourcentagesSurParts]"
        ></Chart>
        <div
            v-else
            class="flex h-[300px] items-center justify-center text-center text-[var(--p-text-muted-color)]"
            data-testid="repartition-vide"
        >
            Aucune commission sur la période
        </div>
    </div>
</template>
