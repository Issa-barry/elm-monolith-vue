<script setup lang="ts">
import { useChartTheme } from '@/composables/useChartTheme';
import Chart from 'primevue/chart';
import { onMounted, ref, watch } from 'vue';

// ── Props (données réelles depuis le backend) ─────────────────────────────────
interface TypeVehiculeData { label: string; montant: number }

const props = defineProps<{
    caParTypeVehicule: TypeVehiculeData[];
}>();

// ── Thème — même pattern que ChartDoc.vue ─────────────────────────────────────
const { getPrimary, getSurface, isDarkTheme } = useChartTheme();

const pieData    = ref({});
const pieOptions = ref({});

// Palette fixe — jusqu'à 5 types de véhicule (TypeVehicule enum)
const BG_VARS    = ['--p-indigo-500', '--p-purple-500', '--p-teal-500', '--p-orange-500', '--p-green-500'];
const HOVER_VARS = ['--p-indigo-400', '--p-purple-400', '--p-teal-400', '--p-orange-400', '--p-green-400'];

function setColorOptions() {
    const s         = getComputedStyle(document.documentElement);
    const textColor = s.getPropertyValue('--text-color');

    pieData.value = {
        labels: props.caParTypeVehicule.map((d) => d.label),
        datasets: [
            {
                data:                 props.caParTypeVehicule.map((d) => d.montant),
                backgroundColor:      BG_VARS.slice(0, props.caParTypeVehicule.length).map((v) => s.getPropertyValue(v)),
                hoverBackgroundColor: HOVER_VARS.slice(0, props.caParTypeVehicule.length).map((v) => s.getPropertyValue(v)),
            },
        ],
    };

    // Options copiées depuis SalesByCategoryWidget.vue (Apollo) + tooltip GNF
    pieOptions.value = {
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
watch(() => props.caParTypeVehicule, () => setColorOptions(), { deep: true });
</script>

<template>
    <div class="card h-full">
        <div class="mb-12 text-xl font-semibold">Catégorie de véhicule</div>

        <Chart
            v-if="caParTypeVehicule.length"
            type="pie"
            :data="pieData"
            :options="pieOptions"
            class="h-[19rem]"
        />
        <div v-else class="flex h-[19rem] items-center justify-center text-sm text-muted-foreground">
            Aucune donnée disponible
        </div>
    </div>
</template>
