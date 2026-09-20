<script setup lang="ts">
import { useChartTheme } from '@/composables/useChartTheme';
import { formatGNF, formatQuantite } from '@/lib/utils';
import type { SituationProduitVendu } from '@/types/vehicule-situation';
import type { Plugin } from 'chart.js';
import Chart from 'primevue/chart';
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    produits: SituationProduitVendu[];
}>();

// Thème — même pattern que les widgets dashboard Apollo (ChartDoc) : couleurs lues sur les
// variables CSS PrimeVue, recalculées quand le thème change.
const { getPrimary, getSurface, isDarkTheme } = useChartTheme();

const chartData = ref({});
const chartOptions = ref({});

const LIBELLE_MAX = 24;
const HAUTEUR_LIGNE = 40;
const HAUTEUR_AXE = 44;

const libelle = (p: SituationProduitVendu): string => p.libelle ?? 'Produit';
const tronquer = (texte: string): string =>
    texte.length > LIBELLE_MAX ? `${texte.slice(0, LIBELLE_MAX - 1)}…` : texte;

const totalQuantite = computed(() =>
    props.produits.reduce((somme, p) => somme + p.quantite, 0),
);
const totalMontant = computed(() =>
    props.produits.reduce((somme, p) => somme + p.montant, 0),
);
// La hauteur inclut la bande de l'axe des quantités : sinon le graphique déborde sur l'axe.
const hauteur = computed(
    () => `${props.produits.length * HAUTEUR_LIGNE + HAUTEUR_AXE}px`,
);

let couleurTexte = '#0f172a';

// Valeur au bout de chaque barre (Chart.js n'a pas d'étiquettes natives ; pas de plugin tiers).
const etiquettesValeurs: Plugin<'bar'> = {
    id: 'etiquettesValeurs',
    afterDatasetsDraw(chart) {
        const { ctx } = chart;
        ctx.save();
        ctx.font = `600 12px ${chart.options.font?.family ?? 'sans-serif'}`;
        ctx.fillStyle = couleurTexte;
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        chart.getDatasetMeta(0).data.forEach((barre, index) => {
            const valeur = chart.data.datasets[0].data[index] as number;
            ctx.fillText(formatQuantite(valeur), barre.x + 8, barre.y);
        });
        ctx.restore();
    },
};
const plugins = [etiquettesValeurs];

function setColorOptions() {
    const style = getComputedStyle(document.documentElement);
    const lire = (variable: string, repli: string) =>
        style.getPropertyValue(variable).trim() || repli;

    const textColor = lire(
        '--p-text-color',
        isDarkTheme.value ? '#e2e8f0' : '#0f172a',
    );
    const textMuted = lire(
        '--p-text-muted-color',
        isDarkTheme.value ? '#94a3b8' : '#64748b',
    );
    const gridColor = lire(
        '--p-content-border-color',
        isDarkTheme.value ? '#334155' : '#e2e8f0',
    );
    couleurTexte = textColor;

    const quantites = props.produits.map((p) => p.quantite);
    const largeurValeurMax =
        formatQuantite(Math.max(0, ...quantites)).length * 8;

    chartData.value = {
        labels: props.produits.map(libelle),
        datasets: [
            {
                data: quantites,
                backgroundColor: lire('--p-primary-500', '#3b82f6'),
                hoverBackgroundColor: lire('--p-primary-600', '#2563eb'),
                // Fin de barre arrondie, base droite sur l'axe.
                borderRadius: { topRight: 4, bottomRight: 4 },
                borderSkipped: false,
                barThickness: 20,
                maxBarThickness: 24,
            },
        ],
    };

    chartOptions.value = {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        font: { family: getComputedStyle(document.body).fontFamily },
        // Place pour la valeur affichée après la plus longue barre.
        layout: { padding: { right: 16 + largeurValeurMax } },
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    title: (items: { dataIndex: number }[]) =>
                        libelle(props.produits[items[0].dataIndex]),
                    label: (ctx: { parsed: { x: number } }) =>
                        ` Quantité vendue : ${formatQuantite(ctx.parsed.x)}`,
                    afterLabel: (ctx: { dataIndex: number }) =>
                        ` Montant : ${formatGNF(props.produits[ctx.dataIndex].montant)}`,
                },
            },
        },
        scales: {
            x: {
                beginAtZero: true,
                border: { display: false },
                grid: { color: gridColor },
                ticks: {
                    color: textMuted,
                    precision: 0,
                    maxTicksLimit: 5,
                    callback: (valeur: string | number) =>
                        new Intl.NumberFormat('fr-FR', {
                            notation: 'compact',
                            maximumFractionDigits: 1,
                        }).format(Number(valeur)),
                },
            },
            y: {
                border: { display: false },
                grid: { display: false },
                ticks: {
                    color: textColor,
                    callback: (index: string | number) =>
                        tronquer(libelle(props.produits[Number(index)])),
                },
            },
        },
    };
}

onMounted(() => setColorOptions());
watch([getPrimary, getSurface, isDarkTheme], () => setColorOptions(), {
    immediate: true,
});
watch(
    () => props.produits,
    () => setColorOptions(),
    { deep: true },
);
</script>

<template>
    <div class="rounded-xl border bg-card p-5 sm:p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-x-4">
            <h4 class="text-base font-semibold">Produits vendus</h4>
            <p v-if="produits.length" class="text-sm text-muted-foreground">
                <span class="font-semibold text-foreground">{{
                    formatQuantite(totalQuantite)
                }}</span>
                vendus · {{ formatGNF(totalMontant) }}
            </p>
        </div>
        <p class="mt-1 text-xs text-muted-foreground">
            Quantité vendue par produit
        </p>

        <div
            v-if="!produits.length"
            class="mt-4 rounded-lg border border-dashed py-10 text-center"
        >
            <p class="text-sm text-muted-foreground">
                Aucun produit vendu sur cette période.
            </p>
        </div>

        <template v-else>
            <div class="mt-4" :style="{ height: hauteur }">
                <Chart
                    type="bar"
                    :data="chartData"
                    :options="chartOptions"
                    :plugins="plugins"
                    :canvas-props="{
                        role: 'img',
                        'aria-label': 'Quantité vendue par produit',
                    }"
                    class="h-full w-full"
                />
            </div>

            <table class="sr-only">
                <caption>
                    Quantité et montant vendus par produit
                </caption>
                <thead>
                    <tr>
                        <th>Produit</th>
                        <th>Quantité vendue</th>
                        <th>Montant</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in produits" :key="p.variante_id">
                        <td>{{ libelle(p) }}</td>
                        <td>{{ formatQuantite(p.quantite) }}</td>
                        <td>{{ formatGNF(p.montant) }}</td>
                    </tr>
                </tbody>
            </table>
        </template>
    </div>
</template>
