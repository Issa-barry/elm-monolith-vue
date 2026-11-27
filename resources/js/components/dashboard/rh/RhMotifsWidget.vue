<script setup lang="ts">
import { partEnPourcentage } from '@/lib/pourcentages';
import type { MotifAbsenceRh } from '@/types/dashboard-rh';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import { computed } from 'vue';
import { CARTE_APOLLO, TITRE_GRAPHIQUE_APOLLO } from '../commissions/apollo';
import { nombreTexte } from './nombres';

// Jours d'absence par motif, du plus grand nombre de jours au plus petit (serveur), avec leur part
// dans le total des jours d'absence. Seuls les motifs présents sur la période apparaissent.
const props = defineProps<{
    lignes: MotifAbsenceRh[];
}>();

const totalJours = computed(() =>
    props.lignes.reduce((somme, l) => somme + l.jours, 0),
);
</script>

<template>
    <div :class="CARTE_APOLLO">
        <div :class="TITRE_GRAPHIQUE_APOLLO" class="mb-1">
            Absences par motif
        </div>
        <p class="mb-5 text-[13px] text-[var(--p-text-muted-color)]">
            Jours calendaires sur les jours écoulés de la période, absences
            annulées exclues.
        </p>
        <DataTable
            :value="lignes"
            data-key="motif_id"
            size="small"
            scrollable
            data-testid="rh-motifs"
        >
            <Column field="libelle" header="Motif" />
            <Column
                header="Absences"
                header-class="justify-end text-right"
                body-class="text-right tabular-nums"
            >
                <template #body="{ data }">{{
                    nombreTexte(data.absences)
                }}</template>
            </Column>
            <Column
                header="Jours"
                header-class="justify-end text-right"
                body-class="text-right tabular-nums"
            >
                <template #body="{ data }">{{
                    nombreTexte(data.jours)
                }}</template>
            </Column>
            <Column
                header="Part des jours"
                header-class="justify-end text-right"
                body-class="text-right tabular-nums whitespace-nowrap"
            >
                <template #body="{ data }">{{
                    partEnPourcentage(data.jours, totalJours)
                }}</template>
            </Column>
            <template #empty>
                <div class="py-6 text-center text-[var(--p-text-muted-color)]">
                    Aucune absence sur la période
                </div>
            </template>
        </DataTable>
    </div>
</template>
