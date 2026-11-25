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
    montantGnf,
} from '../commissions/apollo';

// Même camembert que « Répartition des commissions » (SalesByCategoryWidget d'Apollo : hauteur 300,
// nuances de la couleur primaire, légende en bas), avec le pourcentage de chaque type : dans la
// légende, l'infobulle et sur la part si elle atteint 5 %. Dépenses validées uniquement ; le total
// des parts est égal aux dépenses payées (au-delà de 7 types : « Autres types », serveur).
const props = defineProps<{
    repartition: PartDepenses[];
}>();

const { getPrimary, getSurface, isDarkTheme } = useChartTheme();

// Nuances d'Apollo (700, 400, 100), puis les intermédiaires de la même palette.
const NUANCES = ['700', '400', '100', '500', '200', '800', '300', '600'];
const NUANCES_SURVOL = ['600', '300', '200', '400', '300', '700', '200', '500'];
// Nuances foncées : pourcentage écrit en blanc ; les autres en primaire 900.
const NUANCES_FONCEES = ['700', '500', '800', '600'];
// En dessous de 5 % du total, la part est trop étroite pour y écrire.
const PART_MIN_ETIQUETTE = 0.05;

const pieData = ref({});
const pieOptions = ref({});
const couleurs = ref<string[]>([]);
let couleursEtiquettes: string[] = [];

// Légende : libellé et pourcentage des dépenses payées.
const legende = computed(() => {
    const total = totalParts(props.repartition);

    return props.repartition.map((r) => ({
        id: r.id,
        texte: `${r.label} (${pourcentage(r.montant, total)})`,
    }));
});

// Pourcentage écrit au centre de chaque part assez large (Chart.js n'a pas d'étiquettes natives).
const pourcentagesSurParts = {
    id: 'pourcentagesSurPartsDepenses',
    afterDatasetsDraw(chart: {
        ctx: CanvasRenderingContext2D;
        getDatasetMeta: (i: number) => {
            data: {
                tooltipPosition: (final: boolean) => { x: number; y: number };
            }[];
        };
        getDataVisibility: (i: number) => boolean;
    }) {
        const total = totalParts(props.repartition);
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
    const nuance = (n: string) =>
        documentStyle.getPropertyValue(`--p-primary-${n}`);
    const total = totalParts(props.repartition);

    couleursEtiquettes = props.repartition.map((_, i) =>
        NUANCES_FONCEES.includes(NUANCES[i % NUANCES.length])
            ? '#ffffff'
            : nuance('900'),
    );
    couleurs.value = props.repartition.map((_, i) =>
        nuance(NUANCES[i % NUANCES.length]),
    );

    pieData.value = {
        labels: props.repartition.map((r) => r.label),
        datasets: [
            {
                data: props.repartition.map((r) => r.montant),
                backgroundColor: couleurs.value,
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
            // Légende en HTML sous le graphique (voir le template) : dans le canevas, à hauteur
            // fixe, ses huit entrées étaient coupées sur un écran de téléphone.
            legend: {
                display: false,
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
            Répartition par type de dépense
        </div>
        <template v-if="repartition.length">
            <Chart
                type="pie"
                :data="pieData"
                :height="240"
                :options="pieOptions"
                :plugins="[pourcentagesSurParts]"
            ></Chart>
            <!-- Légende du camembert d'Apollo, en HTML pour passer à la ligne sans être coupée.
                 Mêmes mesures que la légende Chart.js 4.5 d'Apollo (`usePointStyle`, police par
                 défaut en 12 px gras, `padding: 28`) : pastille de 17 px (rayon 12 × √2 / 2), texte
                 à 3,5 px de la pastille, 28 px d'un élément à l'autre (25,5 px après la pastille
                 qui déborde), 40 px d'une ligne à l'autre (23 px sous une pastille de 17 px). -->
            <ul
                class="mt-7 flex flex-wrap justify-center gap-x-[25.5px] gap-y-[23px] font-['Helvetica_Neue',Helvetica,Arial,sans-serif] text-[12px] leading-[12px] font-bold text-[var(--p-text-color)]"
                data-testid="types-legende"
            >
                <li
                    v-for="(part, i) in legende"
                    :key="`${part.id ?? 'autres'}-${i}`"
                    class="flex items-center gap-[3.5px]"
                >
                    <span
                        class="inline-block size-[17px] shrink-0 rounded-full"
                        :style="{ backgroundColor: couleurs[i] }"
                    ></span>
                    {{ part.texte }}
                </li>
            </ul>
        </template>
        <div
            v-else
            class="flex h-[300px] items-center justify-center text-center text-[var(--p-text-muted-color)]"
            data-testid="types-vide"
        >
            Aucune dépense payée sur la période
        </div>
    </div>
</template>
