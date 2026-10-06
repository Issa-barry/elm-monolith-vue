<script setup lang="ts">
import SituationEntete from '@/components/situation/SituationEntete.vue';
import type { SituationPeriode, SituationVentesData } from '@/types/situation';
import SituationVentesSection from './situation/SituationVentesSection.vue';

defineProps<{
    vehiculeId: string;
    vehiculeRecherche: string;
    periode: SituationPeriode;
    ventes: SituationVentesData;
}>();
</script>

<template>
    <div class="space-y-8">
        <SituationEntete
            titre="Situation du véhicule"
            :url="`/backoffice/vehicules/${vehiculeId}`"
            :periode="periode"
        />

        <SituationVentesSection
            :vehicule-recherche="vehiculeRecherche"
            :periode="periode"
            :data="ventes"
        />
    </div>
</template>
