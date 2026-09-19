<script setup lang="ts">
import type {
    SituationPeriode,
    SituationVentesData,
} from '@/types/vehicule-situation';
import { router } from '@inertiajs/vue3';
import SelectButton from 'primevue/selectbutton';
import SituationVentesSection from './situation/SituationVentesSection.vue';

const props = defineProps<{
    vehiculeId: string;
    vehiculeRecherche: string;
    periode: SituationPeriode;
    ventes: SituationVentesData;
}>();

const PERIODE_OPTIONS = [
    { label: 'Tout', value: 'all' },
    { label: 'Mois', value: 'month' },
    { label: 'Année', value: 'year' },
];

// Le filtre se rejoue côté serveur (backend source de vérité) ; preserveState garde l'onglet actif.
function onPeriodeChange(value: SituationPeriode | null): void {
    if (!value || value === props.periode) {
        return;
    }
    router.get(
        `/backoffice/vehicules/${props.vehiculeId}`,
        { situation_periode: value },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}
</script>

<template>
    <div class="space-y-8">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-xl font-semibold tracking-tight">
                Situation du véhicule
            </h2>
            <SelectButton
                :model-value="periode"
                :options="PERIODE_OPTIONS"
                option-label="label"
                option-value="value"
                class="w-max [&_.p-togglebutton]:h-9 [&_.p-togglebutton]:px-3"
                @update:model-value="onPeriodeChange"
            />
        </div>

        <SituationVentesSection
            :vehicule-recherche="vehiculeRecherche"
            :data="ventes"
        />
    </div>
</template>
