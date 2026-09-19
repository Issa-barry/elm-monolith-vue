<script setup lang="ts">
import { useChartTheme } from '@/composables/useChartTheme';
import { formatGNF, formatQuantite } from '@/lib/utils';
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

// Statuts de paiement (vert / ambre / rouge), déjà portés par la palette PrimeVue du projet.
// Étapes choisies avec le validateur du guide dataviz (écart de teinte entre les 3 tranches
// mutuellement adjacentes, en clair et en sombre) ; le libellé et l'icône de chaque ligne du
// tableau doublent la couleur, qui n'est jamais le seul canal.
const PALETTE: Record<
    SituationPaiementCode,
    { clair: [string, string]; sombre: [string, string] }
> = {
    paye: {
        clair: ['--p-emerald-600', '#059669'],
        sombre: ['--p-emerald-600', '#059669'],
    },
    partiel: {
        clair: ['--p-amber-500', '#f59e0b'],
        sombre: ['--p-amber-600', '#d97706'],
    },
    du: {
        clair: ['--p-red-600', '#dc2626'],
        sombre: ['--p-red-700', '#b91c1c'],
    },
};
const ICONES = { paye: CircleCheck, partiel: Contrast, du: CircleAlert };

const couleurs = ref<Record<SituationPaiementCode, string>>({
    paye: '#059669',
    partiel: '#f59e0b',
    du: '#dc2626',
});

function formatPourcentage(valeur: number): string {
    return `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 }).format(valeur)} %`;
}

const pluriel = (n: number) => (n > 1 ? 'ventes' : 'vente');

function setColorOptions() {
    const style = getComputedStyle(document.documentElement);
    const mode = isDarkTheme.value ? 'sombre' : 'clair';
    couleurs.value = Object.fromEntries(
        (
            Object.entries(PALETTE) as [
                SituationPaiementCode,
                (typeof PALETTE)[SituationPaiementCode],
            ][]
        ).map(([code, palette]) => {
            const [variable, repli] = palette[mode];
            return [code, style.getPropertyValue(variable).trim() || repli];
        }),
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
                hoverBackgroundColor: repartition.map(
                    (c) => couleurs.value[c.code],
                ),
                borderColor: surface,
                borderWidth: 2,
                hoverOffset: 4,
            },
        ],
    };

    chartOptions.value = {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '70%',
        layout: { padding: 6 },
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: (ctx: { dataIndex: number }) => {
                        const c = repartition[ctx.dataIndex];
                        return ` ${c.label} : ${formatGNF(c.montant)}`;
                    },
                    afterLabel: (ctx: { dataIndex: number }) => {
                        const c = repartition[ctx.dataIndex];
                        return [
                            ` ${formatPourcentage(c.pourcentage_montant)} du montant`,
                            ` ${c.nb_ventes} ${pluriel(c.nb_ventes)} (${formatPourcentage(c.pourcentage_ventes)} des ventes)`,
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
                class="relative mx-auto mt-4 h-44 w-44"
            >
                <Chart
                    type="doughnut"
                    :data="chartData"
                    :options="chartOptions"
                    :canvas-props="{
                        role: 'img',
                        'aria-label':
                            'Répartition des ventes par situation de paiement',
                    }"
                    class="h-full w-full"
                />
                <div
                    class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center"
                >
                    <span class="text-3xl leading-none font-bold">{{
                        paiements.total_ventes
                    }}</span>
                    <span class="mt-1 text-xs text-muted-foreground">{{
                        pluriel(paiements.total_ventes)
                    }}</span>
                </div>
            </div>

            <div class="mt-5 overflow-x-auto">
                <table class="w-full min-w-[320px] text-sm">
                    <thead class="text-xs text-muted-foreground">
                        <tr>
                            <th
                                rowspan="2"
                                class="pr-2 pb-1.5 text-left align-bottom font-medium"
                            >
                                Statut
                            </th>
                            <th
                                colspan="2"
                                class="border-b px-2 pb-1 text-center font-medium"
                            >
                                Montant
                            </th>
                            <th
                                colspan="2"
                                class="border-b px-2 pb-1 text-center font-medium"
                            >
                                Ventes
                            </th>
                        </tr>
                        <tr>
                            <th class="px-2 py-1 text-right font-normal">
                                GNF
                            </th>
                            <th class="px-2 py-1 text-right font-normal">%</th>
                            <th class="px-2 py-1 text-right font-normal">
                                Nombre
                            </th>
                            <th class="px-2 py-1 text-right font-normal">%</th>
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
                                {{ formatQuantite(ligne.montant) }}
                                <span
                                    v-if="resteSurPartiel(ligne)"
                                    class="block text-xs font-normal text-muted-foreground"
                                >
                                    dont reste
                                    {{
                                        formatQuantite(ligne.reste_a_encaisser)
                                    }}
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
                            <td
                                class="px-2 py-2.5 text-right align-top whitespace-nowrap tabular-nums"
                            >
                                {{
                                    formatPourcentage(ligne.pourcentage_ventes)
                                }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 font-semibold">
                            <td class="py-2.5 pr-2">Total</td>
                            <td
                                class="px-2 py-2.5 text-right whitespace-nowrap tabular-nums"
                            >
                                {{ formatQuantite(paiements.total_montant) }}
                            </td>
                            <td
                                class="px-2 py-2.5 text-right whitespace-nowrap tabular-nums"
                            >
                                100 %
                            </td>
                            <td class="px-2 py-2.5 text-right tabular-nums">
                                {{ paiements.total_ventes }}
                            </td>
                            <td
                                class="px-2 py-2.5 text-right whitespace-nowrap tabular-nums"
                            >
                                100 %
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </template>
    </div>
</template>
