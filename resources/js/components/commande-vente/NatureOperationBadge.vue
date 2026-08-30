<script setup lang="ts">
import { computed } from 'vue';

// Badge de nature/catégorie — jamais un StatusDot : nature_operation n'est pas un statut
// d'entité (le vrai statut de la commande reste StatutCommandeVente), c'est une classification
// figée à la création, donc autorisée à garder un fond coloré (règle UI du projet).
const props = defineProps<{
    nature: 'vente_standard' | 'distribution_client';
}>();

const label = computed(() =>
    props.nature === 'distribution_client' ? 'Distribution client' : 'Vente',
);

const classes = computed(() =>
    props.nature === 'distribution_client'
        ? 'bg-violet-50 text-violet-700 dark:bg-violet-950 dark:text-violet-300'
        : 'bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
);
</script>

<template>
    <span
        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
        :class="classes"
    >
        {{ label }}
    </span>
</template>
