<script setup lang="ts">
import FilterMultiSelect from '@/components/filters/FilterMultiSelect.vue';

defineProps<{
    options: { value: string; label: string }[];
}>();

const model = defineModel<(string | number)[]>({ default: () => [] });
</script>

<template>
    <div class="commission-vehicule-select w-full sm:w-72">
        <FilterMultiSelect
            v-model="model"
            :options="options"
            placeholder="Tous les véhicules"
            class="text-sm"
        />
    </div>
</template>

<style scoped>
/* Le chip sélectionné (PrimeVue Chip) n'hérite pas toujours de text-sm
   posé sur la racine du MultiSelect — on force sa taille ici, une seule
   fois, pour les 3 pages détail commission qui réutilisent ce composant. */
.commission-vehicule-select :deep(.p-chip),
.commission-vehicule-select :deep(.p-chip-label) {
    font-size: 0.75rem;
    line-height: 1rem;
}
</style>
