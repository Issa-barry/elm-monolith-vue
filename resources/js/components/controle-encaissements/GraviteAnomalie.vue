<script setup lang="ts">
import { Info, OctagonAlert, TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';

// Gravité d'une anomalie du contrôle des encaissements (GraviteAnomalieEncaissement) : une
// catégorie, pas un statut d'entité — rouge = écart certain, ambre = à vérifier, bleu = information.
const props = defineProps<{ gravite: string; label: string }>();

const style = computed(
    () =>
        ({
            critique: {
                icone: OctagonAlert,
                classe: 'text-red-600 dark:text-red-400',
            },
            attention: {
                icone: TriangleAlert,
                classe: 'text-amber-600 dark:text-amber-400',
            },
        })[props.gravite] ?? {
            icone: Info,
            classe: 'text-blue-600 dark:text-blue-400',
        },
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 text-sm font-medium"
        :class="style.classe"
        :data-gravite="gravite"
    >
        <component :is="style.icone" class="h-4 w-4 shrink-0" />
        {{ label }}
    </span>
</template>
