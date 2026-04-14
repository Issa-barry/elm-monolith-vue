<script setup lang="ts">
import { useChartTheme } from '@/composables/useChartTheme';
import Chart from 'primevue/chart';
import { onMounted, ref, watch } from 'vue';

// ── Props (données réelles depuis le backend) ─────────────────────────────────
interface SiteData { nom: string; montant: number }

const props = defineProps<{
    caParSite: SiteData[];
}>();

// ── Thème — même pattern que ChartDoc.vue ─────────────────────────────────────
const { getPrimary, getSurface, isDarkTheme } = useChartTheme();

const doughnutData    = ref({});
const doughnutOptions = ref({});

// Palette fixe — étendue à 6 sites maximum
const BG_VARS    = ['--p-indigo-500', '--p-purple-500', '--p-teal-500', '--p-orange-500', '--p-green-500', '--p-cyan-500'];
const HOVER_VARS = ['--p-indigo-400', '--p-purple-400', '--p-teal-400', '--p-orange-400', '--p-green-400', '--p-cyan-400'];

function setColorOptions() {
    const s         = getComputedStyle(document.documentElement);
    const textColor = s.getPropertyValue('--text-color');

    doughnutData.value = {
        labels: props.caParSite.map((d) => d.nom),
        datasets: [
            {
                data:                 props.caParSite.map((d) => d.montant),
                backgroundColor:      BG_VARS.slice(0, props.caParSite.length).map((v) => s.getPropertyValue(v)),
                hoverBackgroundColor: HOVER_VARS.slice(0, props.caParSite.length).map((v) => s.getPropertyValue(v)),
            },
        ],
    };

    // Options copiées depuis ChartDoc.vue section Doughnut + tooltip GNF
    doughnutOptions.value = {
        plugins: {
            legend: {
                labels: { usePointStyle: true, color: textColor },
            },
            tooltip: {
                callbacks: {
                    label(ctx: { label: string; parsed: number }) {
                        return ` ${ctx.label} : ${new Intl.NumberFormat('fr-FR').format(ctx.parsed)} GNF`;
                    },
                },
            },
        },
    };
}

onMounted(() => setColorOptions());
watch([getPrimary, getSurface, isDarkTheme], () => setColorOptions(), { immediate: true });
watch(() => props.caParSite, () => setColorOptions(), { deep: true });
</script>

<template>
    <!-- card flex flex-col items-center — Apollo ChartDoc exact -->
    <div class="card flex flex-col items-center">
        <div class="mb-4 text-xl font-semibold">CA par site</div>

        <Chart
            v-if="caParSite.length"
            type="doughnut"
            :data="doughnutData"
            :options="doughnutOptions"
        />
        <div v-else class="flex h-48 items-center justify-center text-sm text-muted-foreground">
            Aucune donnée disponible
        </div>
    </div>
</template>
