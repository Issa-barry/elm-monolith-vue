<script setup lang="ts">
import { useChartTheme } from '@/composables/useChartTheme';
import { formatGNF } from '@/lib/utils';
import type {
    SituationPaiementCategorie,
    SituationPaiementCode,
    SituationPaiements,
} from '@/types/vehicule-situation';
import { CircleAlert, CircleCheck, Contrast } from 'lucide-vue-next';
import Chart from 'primevue/chart';
import { onMounted, ref, watch } from 'vue';

const props = defineProps<{
    paiements: SituationPaiements;
}>();

const { getPrimary, getSurface, isDarkTheme } = useChartTheme();

const chartData = ref({});
const chartOptions = ref({});
const carte = ref<HTMLElement | null>(null);

type Etape = [variable: string, repli: string];
type Mode = 'clair' | 'sombre';

// Statuts de paiement (vert / ambre / rouge), déjà portés par la palette PrimeVue du projet.
// Étapes « base » choisies avec le validateur du guide dataviz (écart de teinte entre les 3
// tranches mutuellement adjacentes, en clair et en sombre) ; le libellé et l'icône de chaque
// ligne du tableau doublent la couleur, qui n'est jamais le seul canal. « survol » = un cran
// plus clair, comme les hoverBackgroundColor du camembert Apollo (ChartDoc).
const PALETTE: Record<
    SituationPaiementCode,
    Record<Mode, { base: Etape; survol: Etape }>
> = {
    paye: {
        clair: {
            base: ['--p-emerald-600', '#059669'],
            survol: ['--p-emerald-500', '#10b981'],
        },
        sombre: {
            base: ['--p-emerald-600', '#059669'],
            survol: ['--p-emerald-500', '#10b981'],
        },
    },
    partiel: {
        clair: {
            base: ['--p-amber-500', '#f59e0b'],
            survol: ['--p-amber-400', '#fbbf24'],
        },
        sombre: {
            base: ['--p-amber-600', '#d97706'],
            survol: ['--p-amber-500', '#f59e0b'],
        },
    },
    impaye: {
        clair: {
            base: ['--p-red-600', '#dc2626'],
            survol: ['--p-red-500', '#ef4444'],
        },
        sombre: {
            base: ['--p-red-700', '#b91c1c'],
            survol: ['--p-red-600', '#dc2626'],
        },
    },
};
const ICONES = { paye: CircleCheck, partiel: Contrast, impaye: CircleAlert };

const couleurs = ref<Record<SituationPaiementCode, string>>({
    paye: '#059669',
    partiel: '#f59e0b',
    impaye: '#dc2626',
});

function formatPourcentage(valeur: number): string {
    return `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 }).format(valeur)} %`;
}

const pluriel = (n: number) => (n > 1 ? 'ventes' : 'vente');

function setColorOptions() {
    const style = getComputedStyle(document.documentElement);
    const mode: Mode = isDarkTheme.value ? 'sombre' : 'clair';
    const lire = ([variable, repli]: Etape) =>
        style.getPropertyValue(variable).trim() || repli;
    const codes = Object.keys(PALETTE) as SituationPaiementCode[];
    couleurs.value = Object.fromEntries(
        codes.map((code) => [code, lire(PALETTE[code][mode].base)]),
    ) as Record<SituationPaiementCode, string>;

    // Interstice de 2 px entre tranches = couleur exacte de la carte (jamais un contour).
    const surface = carte.value
        ? getComputedStyle(carte.value).backgroundColor
        : isDarkTheme.value
          ? '#1a1a19'
          : '#ffffff';

    const { repartition } = props.paiements;

    chartData.value = {
        labels: repartition.map((c) => c.label),
        datasets: [
            {
                data: repartition.map((c) => c.montant),
                backgroundColor: repartition.map((c) => couleurs.value[c.code]),
                hoverBackgroundColor: repartition.map((c) =>
                    lire(PALETTE[c.code][mode].survol),
                ),
                borderColor: surface,
                borderWidth: 2,
                hoverOffset: 8,
            },
        ],
    };

    // Camembert plein (Apollo « Pie ») : rien n'est dessiné par-dessus le canvas, l'infobulle
    // reste donc entièrement lisible. Le titre de l'infobulle porte déjà le statut : le corps
    // ne le répète pas.
    chartOptions.value = {
        responsive: true,
        maintainAspectRatio: false,
        layout: { padding: 10 },
        plugins: {
            legend: { display: false },
            tooltip: {
                padding: 12,
                boxPadding: 6,
                bodySpacing: 6,
                titleFont: { size: 14, weight: 600 },
                bodyFont: { size: 13 },
                callbacks: {
                    label: (ctx: { dataIndex: number }) =>
                        ` ${formatGNF(repartition[ctx.dataIndex].montant)}`,
                    afterLabel: (ctx: { dataIndex: number }) => {
                        const c = repartition[ctx.dataIndex];
                        return [
                            ` ${formatPourcentage(c.pourcentage_montant)} du montant`,
                            ` ${c.nb_ventes} ${pluriel(c.nb_ventes)}`,
                        ];
                    },
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
    () => props.paiements,
    () => setColorOptions(),
    { deep: true },
);

const resteSurPartiel = (ligne: SituationPaiementCategorie): boolean =>
    ligne.code === 'partiel' && ligne.reste_a_encaisser > 0;
</script>

<template>
    <div ref="carte" class="rounded-xl border bg-card p-5 sm:p-6">
        <h4 class="text-base font-semibold">Situation des paiements</h4>
        <p class="mt-1 text-xs text-muted-foreground">
            Ventes classées selon l'état d'encaissement de leur facture
        </p>

        <div
            v-if="paiements.total_ventes === 0"
            class="mt-4 rounded-lg border border-dashed py-10 text-center"
        >
            <p class="text-sm text-muted-foreground">
                Aucune vente facturée sur cette période.
            </p>
        </div>

        <template v-else>
            <div
                v-if="paiements.total_montant > 0"
                class="mx-auto mt-4 h-56 w-56"
            >
                <Chart
                    type="pie"
                    :data="chartData"
                    :options="chartOptions"
                    :canvas-props="{
                        role: 'img',
                        'aria-label':
                            'Répartition des ventes par situation de paiement',
                    }"
                    class="h-full w-full"
                />
            </div>

            <div class="mt-5 overflow-x-auto">
                <table class="w-full min-w-[340px] text-sm">
                    <thead class="text-xs text-muted-foreground">
                        <tr class="border-b">
                            <th
                                class="pr-2 pb-2 text-left align-bottom font-medium"
                            >
                                Statut
                            </th>
                            <th
                                class="px-2 pb-2 text-right align-bottom font-medium"
                            >
                                Montant
                            </th>
                            <th
                                class="px-2 pb-2 text-right align-bottom font-medium"
                                title="Part du montant total des ventes"
                            >
                                %
                            </th>
                            <th
                                class="px-2 pb-2 text-right align-bottom font-medium"
                            >
                                Nombre de ventes
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="ligne in paiements.repartition"
                            :key="ligne.code"
                            class="border-t"
                        >
                            <td class="py-2.5 pr-2 align-top">
                                <span
                                    class="inline-flex items-center gap-2 font-medium"
                                >
                                    <component
                                        :is="ICONES[ligne.code]"
                                        class="h-4 w-4 shrink-0"
                                        :style="{ color: couleurs[ligne.code] }"
                                        aria-hidden="true"
                                    />
                                    {{ ligne.label }}
                                </span>
                            </td>
                            <td
                                class="px-2 py-2.5 text-right align-top font-semibold whitespace-nowrap tabular-nums"
                            >
                                {{ formatGNF(ligne.montant) }}
                                <span
                                    v-if="resteSurPartiel(ligne)"
                                    class="block text-xs font-normal text-muted-foreground"
                                >
                                    dont reste à payer
                                    {{ formatGNF(ligne.reste_a_encaisser) }}
                                </span>
                            </td>
                            <td
                                class="px-2 py-2.5 text-right align-top whitespace-nowrap tabular-nums"
                            >
                                {{
                                    formatPourcentage(ligne.pourcentage_montant)
                                }}
                            </td>
                            <td
                                class="px-2 py-2.5 text-right align-top font-semibold tabular-nums"
                            >
                                {{ ligne.nb_ventes }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 font-semibold">
                            <td class="py-2.5 pr-2">Total</td>
                            <td
                                class="px-2 py-2.5 text-right whitespace-nowrap tabular-nums"
                            >
                                {{ formatGNF(paiements.total_montant) }}
                            </td>
                            <td
                                class="px-2 py-2.5 text-right whitespace-nowrap tabular-nums"
                            >
                                100 %
                            </td>
                            <td class="px-2 py-2.5 text-right tabular-nums">
                                {{ paiements.total_ventes }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </template>
    </div>
</template>
