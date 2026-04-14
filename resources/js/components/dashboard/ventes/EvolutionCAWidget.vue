<script setup lang="ts">
import { useChartTheme } from '@/composables/useChartTheme';
import Chart from 'primevue/chart';
import Select from 'primevue/select';
import { ref, watch } from 'vue';

// ── Props (données réelles depuis le backend) ─────────────────────────────────
interface MoisData  { payees: number; partielles: number; impayees: number }
interface JourData  { date: string; payees: number; partielles: number; impayees: number }

const props = defineProps<{
    evolutionMensuelle:    MoisData[];  // 12 entrées, index 0 = Jan
    evolutionQuotidienne:  JourData[];  // 14 entrées, index 0 = J-13, index 13 = aujourd'hui
}>();

// ── Thème (pattern Apollo) ────────────────────────────────────────────────────
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

const selectedPeriode = ref(periodes[12]); // "Cette année" par défaut

// ── Chart refs (pattern Apollo) ───────────────────────────────────────────────
const barOptions = ref({});
const barData    = ref({});

// ── Helpers ───────────────────────────────────────────────────────────────────
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
    const mensuel    = props.evolutionMensuelle    ?? Array(12).fill({ payees: 0, partielles: 0, impayees: 0 });
    const quotidien  = props.evolutionQuotidienne  ?? Array(14).fill({ date: '', payees: 0, partielles: 0, impayees: 0 });

    const sliceMois = (start: number, end: number): PeriodeResult => ({
        labels:     MOIS_LABELS.slice(start, end),
        payees:     mensuel.slice(start, end).map((d) => d.payees),
        partielles: mensuel.slice(start, end).map((d) => d.partielles),
        impayees:   mensuel.slice(start, end).map((d) => d.impayees),
    });

    const sliceJours = (startIdx: number, endIdx: number): PeriodeResult => {
        const slice = quotidien.slice(startIdx, endIdx);
        return {
            labels:     slice.map((d) => fmtDate(d.date)),
            payees:     slice.map((d) => d.payees),
            partielles: slice.map((d) => d.partielles),
            impayees:   slice.map((d) => d.impayees),
        };
    };

    // Filtre les jours du tableau qui appartiennent à un mois donné (ex: "2026-04")
    const filterMois = (ym: string): PeriodeResult => {
        const slice = quotidien.filter((d) => d.date.startsWith(ym));
        return {
            labels:     slice.map((d) => fmtDate(d.date)),
            payees:     slice.map((d) => d.payees),
            partielles: slice.map((d) => d.partielles),
            impayees:   slice.map((d) => d.impayees),
        };
    };

    const now  = new Date();
    const yr   = now.getFullYear();
    const mo   = now.getMonth(); // 0-based

    const ymCourant = `${yr}-${String(mo + 1).padStart(2, '0')}`;
    const prevDate  = new Date(yr, mo - 1, 1);
    const ymPrec    = `${prevDate.getFullYear()}-${String(prevDate.getMonth() + 1).padStart(2, '0')}`;

    // ── Calcul des bornes de semaine (lundi = début) ──────────────────────────
    // index 59 = aujourd'hui, index 0 = il y a 59 jours
    const LAST = quotidien.length - 1;                     // 59
    const joursSinceLundi = (now.getDay() + 6) % 7;        // 0=Lun … 6=Dim
    const lundiCetteIdx   = LAST - joursSinceLundi;
    const lundiPrecIdx    = lundiCetteIdx - 7;

    switch (selectedPeriode.value.value) {
        // ── Journalier ────────────────────────────────────────────────────────
        case 'aujourd_hui':      return sliceJours(LAST, LAST + 1);
        case 'hier':             return sliceJours(LAST - 1, LAST);
        case 'cette_semaine':    return sliceJours(Math.max(0, lundiCetteIdx), LAST + 1);
        case 'semaine_derniere': return sliceJours(Math.max(0, lundiPrecIdx), Math.max(0, lundiCetteIdx));
        // ── Mensuel (tous les jours du mois) ─────────────────────────────────
        case 'ce_mois':          return filterMois(ymCourant);
        case 'mois_dernier':     return filterMois(ymPrec);
        // ── Trimestriel ───────────────────────────────────────────────────────
        case 't1':               return sliceMois(0, 3);
        case 't2':               return sliceMois(3, 6);
        case 't3':               return sliceMois(6, 9);
        case 't4':               return sliceMois(9, 12);
        // ── Semestriel ────────────────────────────────────────────────────────
        case 's1':               return sliceMois(0, 6);
        case 's2':               return sliceMois(6, 12);
        // ── Annuel ────────────────────────────────────────────────────────────
        default:                 return sliceMois(0, 12);
    }
}

// ── initChart — pattern Apollo exact ─────────────────────────────────────────
function initChart() {
    const documentStyle      = getComputedStyle(document.documentElement);
    const textColor          = documentStyle.getPropertyValue('--text-color').trim();
    const textColorSecondary = documentStyle.getPropertyValue('--text-color-secondary').trim();
    const surfaceBorder      = documentStyle.getPropertyValue('--surface-border').trim()
                            || documentStyle.getPropertyValue('--p-content-border-color').trim()
                            || '#e2e8f0';

    const green  = documentStyle.getPropertyValue('--p-green-500')  || '#22c55e';
    const orange = documentStyle.getPropertyValue('--p-orange-500') || '#f97316';
    const red    = documentStyle.getPropertyValue('--p-red-500')    || '#ef4444';

    const data = getPeriodeData();

    barData.value = {
        labels: data.labels,
        datasets: [
            {
                label: 'Payées',
                backgroundColor: green,
                barThickness: 12,
                borderRadius: 12,
                data: data.payees,
            },
            {
                label: 'Partielles',
                backgroundColor: orange,
                barThickness: 12,
                borderRadius: 12,
                data: data.partielles,
            },
            {
                label: 'Impayées',
                backgroundColor: red,
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
                grid: { display: false },
                border: { display: false },
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
                grid: { color: surfaceBorder },
                border: { display: false },
            },
        },
    };
}

// Pattern Apollo exact : watch avec immediate: true
watch([getPrimary, getSurface, isDarkTheme], () => initChart(), { immediate: true });
// Recalcul si les données backend changent
watch(() => [props.evolutionMensuelle, props.evolutionQuotidienne], () => initChart(), { deep: true });
</script>

<template>
    <div class="card h-full">
        <div class="mb-12 flex items-start justify-between">
            <span class="text-surface-900 dark:text-surface-0 text-xl font-semibold">
                Évolution CA Payées vs Impayées
            </span>
            <Select
                v-model="selectedPeriode"
                :options="periodes"
                option-label="label"
                class="w-44"
                @change="initChart"
            />
        </div>

        <Chart type="bar" :height="300" :data="barData" :options="barOptions" />
    </div>
</template>
