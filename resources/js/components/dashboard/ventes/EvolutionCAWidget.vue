<script setup lang="ts">
import { useChartTheme } from '@/composables/useChartTheme';
import Chart from 'primevue/chart';
import Select from 'primevue/select';
import { onMounted, ref, watch } from 'vue';

// ── Props ─────────────────────────────────────────────────────────────────────
interface MoisData { payees: number; partielles: number; impayees: number }
interface JourData { date: string; payees: number; partielles: number; impayees: number }

const props = defineProps<{
    evolutionMensuelle:   MoisData[];
    evolutionQuotidienne: JourData[];
}>();

// ── Thème — même pattern que ChartDoc.vue (useLayout remplacé par useChartTheme)
const { getPrimary, getSurface, isDarkTheme } = useChartTheme();

// ── Périodes ──────────────────────────────────────────────────────────────────
const periodes = [
    { label: "Aujourd'hui",      value: 'aujourd_hui' },
    { label: 'Hier',             value: 'hier' },
    { label: 'Cette semaine',    value: 'cette_semaine' },
    { label: 'Semaine dernière', value: 'semaine_derniere' },
    { label: 'Ce mois',          value: 'ce_mois' },
    { label: 'Mois dernier',     value: 'mois_dernier' },
    { label: 'T1',               value: 't1' },
    { label: 'T2',               value: 't2' },
    { label: 'T3',               value: 't3' },
    { label: 'T4',               value: 't4' },
    { label: 'S1',               value: 's1' },
    { label: 'S2',               value: 's2' },
    { label: 'Cette année',      value: 'cette_annee' },
];

const selectedPeriode = ref(periodes[0]); // "Aujourd'hui" par défaut

// ── Chart refs — nommage identique à ChartDoc ─────────────────────────────────
const barData    = ref({});
const barOptions = ref({});

// ── Helpers période ───────────────────────────────────────────────────────────
const MOIS_LABELS = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin',
                     'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];

function fmtDate(iso: string) {
    const [, m, d] = iso.split('-');
    return `${d}/${m}`;
}

interface PeriodeResult {
    labels: string[];
    payees: number[];
    partielles: number[];
    impayees: number[];
}

function getPeriodeData(): PeriodeResult {
    const mensuel   = props.evolutionMensuelle   ?? Array(12).fill({ payees: 0, partielles: 0, impayees: 0 });
    const quotidien = props.evolutionQuotidienne ?? Array(60).fill({ date: '', payees: 0, partielles: 0, impayees: 0 });

    const sliceMois = (start: number, end: number): PeriodeResult => ({
        labels:     MOIS_LABELS.slice(start, end),
        payees:     mensuel.slice(start, end).map((d) => d.payees),
        partielles: mensuel.slice(start, end).map((d) => d.partielles),
        impayees:   mensuel.slice(start, end).map((d) => d.impayees),
    });

    const sliceJours = (startIdx: number, endIdx: number): PeriodeResult => {
        const slice = quotidien.slice(startIdx, endIdx);
        return {
            labels:     slice.map((d: JourData) => fmtDate(d.date)),
            payees:     slice.map((d: JourData) => d.payees),
            partielles: slice.map((d: JourData) => d.partielles),
            impayees:   slice.map((d: JourData) => d.impayees),
        };
    };

    const filterMois = (ym: string): PeriodeResult => {
        const slice = quotidien.filter((d: JourData) => d.date.startsWith(ym));
        return {
            labels:     slice.map((d: JourData) => fmtDate(d.date)),
            payees:     slice.map((d: JourData) => d.payees),
            partielles: slice.map((d: JourData) => d.partielles),
            impayees:   slice.map((d: JourData) => d.impayees),
        };
    };

    const now  = new Date();
    const yr   = now.getFullYear();
    const mo   = now.getMonth();

    const ymCourant = `${yr}-${String(mo + 1).padStart(2, '0')}`;
    const prevDate  = new Date(yr, mo - 1, 1);
    const ymPrec    = `${prevDate.getFullYear()}-${String(prevDate.getMonth() + 1).padStart(2, '0')}`;

    const LAST            = quotidien.length - 1;          // 59
    const joursSinceLundi = (now.getDay() + 6) % 7;        // 0=Lun … 6=Dim
    const lundiCetteIdx   = LAST - joursSinceLundi;
    const lundiPrecIdx    = lundiCetteIdx - 7;

    switch (selectedPeriode.value.value) {
        case 'aujourd_hui':      return sliceJours(LAST, LAST + 1);
        case 'hier':             return sliceJours(LAST - 1, LAST);
        case 'cette_semaine':    return sliceJours(Math.max(0, lundiCetteIdx), LAST + 1);
        case 'semaine_derniere': return sliceJours(Math.max(0, lundiPrecIdx), Math.max(0, lundiCetteIdx));
        case 'ce_mois':          return filterMois(ymCourant);
        case 'mois_dernier':     return filterMois(ymPrec);
        case 't1':               return sliceMois(0, 3);
        case 't2':               return sliceMois(3, 6);
        case 't3':               return sliceMois(6, 9);
        case 't4':               return sliceMois(9, 12);
        case 's1':               return sliceMois(0, 6);
        case 's2':               return sliceMois(6, 12);
        default:                 return sliceMois(0, 12);
    }
}

// ── setColorOptions ── copie exacte de RevenueOverviewWidget.vue (Apollo) ─────
function setColorOptions() {
    const documentStyle      = getComputedStyle(document.documentElement);
    const textColor          = documentStyle.getPropertyValue('--text-color');
    const textColorSecondary = documentStyle.getPropertyValue('--text-color-secondary');
    const surfaceBorder      = documentStyle.getPropertyValue('--p-content-border-color').trim() || '#e2e8f0';

    const data = getPeriodeData();

    barData.value = {
        labels: data.labels,
        datasets: [
            {
                label: 'Payées',
                backgroundColor: documentStyle.getPropertyValue('--p-green-500'),
                borderColor:     documentStyle.getPropertyValue('--p-green-500'),
                barThickness: 12,
                borderRadius: 12,
                data: data.payees,
            },
            {
                label: 'Partielles',
                backgroundColor: documentStyle.getPropertyValue('--p-orange-500'),
                borderColor:     documentStyle.getPropertyValue('--p-orange-500'),
                barThickness: 12,
                borderRadius: 12,
                data: data.partielles,
            },
            {
                label: 'Impayées',
                backgroundColor: documentStyle.getPropertyValue('--p-red-500'),
                borderColor:     documentStyle.getPropertyValue('--p-red-500'),
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
                    label(ctx: { dataset: { label: string }; parsed: { y: number } }) {
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
    [getPrimary, getSurface, isDarkTheme],
    () => {
        setColorOptions();
    },
    { immediate: true },
);

// Recalcul si données ou période changent
watch(() => [props.evolutionMensuelle, props.evolutionQuotidienne], () => setColorOptions(), { deep: true });
</script>

<template>
    <div class="card">
        <div class="mb-6 flex items-start justify-between">
            <span class="text-surface-900 dark:text-surface-0 font-semibold text-xl">
                Évolution CA Payées vs Impayées
            </span>
            <Select
                v-model="selectedPeriode"
                :options="periodes"
                option-label="label"
                class="w-44"
                @change="setColorOptions"
            />
        </div>

        <!-- class="h-[24rem]" — pattern ChartDoc, pas de :height prop -->
        <Chart type="bar" :data="barData" :options="barOptions" class="h-[24rem]" />
    </div>
</template>
