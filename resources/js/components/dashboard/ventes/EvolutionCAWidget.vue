<script setup lang="ts">
import { useChartTheme } from '@/composables/useChartTheme';
import type { PeriodeOption } from '@/types/periode';
import Chart from 'primevue/chart';
import Select from 'primevue/select';
import { computed, onMounted, ref, watch } from 'vue';

// ── Props ─────────────────────────────────────────────────────────────────────
interface MoisData {
    payees: number;
    partielles: number;
    impayees: number;
}
interface JourData {
    date: string;
    payees: number;
    partielles: number;
    impayees: number;
}

const props = defineProps<{
    evolutionMensuelle: MoisData[];
    evolutionQuotidienne: JourData[];
    periode?: string;
    periodes?: PeriodeOption[];
}>();

// ── Thème — même pattern que ChartDoc.vue (useLayout remplacé par useChartTheme)
const { getPrimary, getSurface, isDarkTheme } = useChartTheme();

// ── Périodes ──────────────────────────────────────────────────────────────────
// Choix et bornes résolus par le serveur (Periode, ADR 0057) : le découpage du graphique ne lit
// jamais l'horloge du navigateur.
const periodes = computed(() => props.periodes ?? []);

const selectedPeriode = ref<PeriodeOption | undefined>(
    periodes.value.find((p) => p.value === props.periode),
);

/** Périodes affichées mois par mois (série mensuelle) plutôt que jour par jour. */
const PERIODES_MENSUELLES = new Set([
    't1',
    't2',
    't3',
    't4',
    's1',
    's2',
    'cette_annee',
    'annee_precedente',
    'tout',
]);

// ── Chart refs — nommage identique à ChartDoc ─────────────────────────────────
const barData = ref({});
const barOptions = ref({});

// ── Helpers période ───────────────────────────────────────────────────────────
const MOIS_LABELS = [
    'Jan',
    'Fév',
    'Mar',
    'Avr',
    'Mai',
    'Juin',
    'Juil',
    'Août',
    'Sep',
    'Oct',
    'Nov',
    'Déc',
];

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
    const mensuel =
        props.evolutionMensuelle ??
        Array(12).fill({ payees: 0, partielles: 0, impayees: 0 });
    const quotidien =
        props.evolutionQuotidienne ??
        Array(60).fill({ date: '', payees: 0, partielles: 0, impayees: 0 });

    const sliceMois = (start: number, end: number): PeriodeResult => ({
        labels: MOIS_LABELS.slice(start, end),
        payees: mensuel.slice(start, end).map((d) => d.payees),
        partielles: mensuel.slice(start, end).map((d) => d.partielles),
        impayees: mensuel.slice(start, end).map((d) => d.impayees),
    });

    const choix = selectedPeriode.value;
    if (
        !choix?.date_debut ||
        !choix.date_fin ||
        PERIODES_MENSUELLES.has(choix.value)
    ) {
        // Mois du 1er au dernier jour du choix ; toute l'année sans bornes.
        const debut = choix?.date_debut
            ? Number(choix.date_debut.slice(5, 7)) - 1
            : 0;
        const fin = choix?.date_fin ? Number(choix.date_fin.slice(5, 7)) : 12;
        return sliceMois(debut, fin);
    }

    const { date_debut: debut, date_fin: fin } = choix;
    const slice = quotidien.filter(
        (d: JourData) => d.date >= debut && d.date <= fin,
    );
    return {
        labels: slice.map((d: JourData) => fmtDate(d.date)),
        payees: slice.map((d: JourData) => d.payees),
        partielles: slice.map((d: JourData) => d.partielles),
        impayees: slice.map((d: JourData) => d.impayees),
    };
}

// ── setColorOptions ── copie exacte de RevenueOverviewWidget.vue (Apollo) ─────
function setColorOptions() {
    const documentStyle = getComputedStyle(document.documentElement);
    const textColor =
        documentStyle.getPropertyValue('--p-text-color').trim() ||
        (isDarkTheme.value ? '#e2e8f0' : '#0f172a');
    const textColorSecondary =
        documentStyle.getPropertyValue('--p-text-muted-color').trim() ||
        (isDarkTheme.value ? '#94a3b8' : '#64748b');
    const surfaceBorder =
        documentStyle.getPropertyValue('--p-content-border-color').trim() ||
        (isDarkTheme.value ? '#334155' : '#e2e8f0');

    const data = getPeriodeData();

    barData.value = {
        labels: data.labels,
        datasets: [
            {
                label: 'Payées',
                backgroundColor:
                    documentStyle.getPropertyValue('--p-green-500'),
                borderColor: documentStyle.getPropertyValue('--p-green-500'),
                barThickness: 12,
                borderRadius: 12,
                data: data.payees,
            },
            {
                label: 'Partielles',
                backgroundColor:
                    documentStyle.getPropertyValue('--p-orange-500'),
                borderColor: documentStyle.getPropertyValue('--p-orange-500'),
                barThickness: 12,
                borderRadius: 12,
                data: data.partielles,
            },
            {
                label: 'Impayées',
                backgroundColor: documentStyle.getPropertyValue('--p-red-500'),
                borderColor: documentStyle.getPropertyValue('--p-red-500'),
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
                    label(ctx: {
                        dataset: { label: string };
                        parsed: { y: number };
                    }) {
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

function changePeriode() {
    setColorOptions();
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
watch(
    () => [props.evolutionMensuelle, props.evolutionQuotidienne],
    () => setColorOptions(),
    { deep: true },
);

watch(
    () => props.periode,
    (val) => {
        const found = periodes.value.find((p) => p.value === val);
        if (found) {
            selectedPeriode.value = found;
            setColorOptions();
        }
    },
);
</script>

<template>
    <div class="card">
        <div class="mb-6 flex items-start justify-between">
            <span
                class="text-surface-900 dark:text-surface-0 text-xl font-semibold"
            >
                Évolution CA Payées vs Impayées
            </span>
            <Select
                v-model="selectedPeriode"
                :options="periodes"
                option-label="label"
                class="w-44"
                @change="changePeriode"
            />
        </div>

        <!-- class="h-[24rem]" — pattern ChartDoc, pas de :height prop -->
        <Chart
            type="bar"
            :data="barData"
            :options="barOptions"
            class="h-[24rem]"
        />
    </div>
</template>
