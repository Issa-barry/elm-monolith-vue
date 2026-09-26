<script setup lang="ts">
import { computed } from 'vue';

// Badge de catégorie/type — jamais un StatusDot : le processus de commission (identité
// Vente/Distribution client/Transfert grossiste, cf. CommissionProcessusDefaults::identiteCodePourVente())
// n'est pas un statut d'entité, c'est une classification figée à la création de la commande,
// donc autorisée à garder un fond coloré (règle UI du projet).
const props = defineProps<{
    processus: string;
    label: string;
}>();

const classes = computed(() => {
    switch (props.processus) {
        case 'distribution_client':
            return 'bg-violet-50 text-violet-700 dark:bg-violet-950 dark:text-violet-300';
        case 'transfert_grossiste':
            return 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300';
        case 'logistique_transfert':
            return 'bg-slate-50 text-slate-700 dark:bg-slate-900 dark:text-slate-300';
        default:
            return 'bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-300';
    }
});
</script>

<template>
    <span
        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
        :class="classes"
    >
        {{ label }}
    </span>
</template>
