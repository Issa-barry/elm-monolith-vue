<script setup lang="ts">
import Select from 'primevue/select';
import { computed } from 'vue';

export interface SearchSelectOption {
    value: string | number;
    label: string;
}

const props = withDefaults(
    defineProps<{
        options: SearchSelectOption[];
        placeholder?: string;
        disabled?: boolean;
    }>(),
    {
        placeholder: 'Rechercher…',
        disabled: false,
    },
);

// Même contrat que FilterMultiSelect (tableau de 0 ou 1 valeur) : DataFilters garde un seul format
// d'état pour tous ses champs `select`, seul l'affichage change (recherche par nom + croix d'effacement).
const model = defineModel<(string | number)[]>({ default: () => [] });

const valeur = computed(() => model.value[0] ?? null);

// Infobulle : le nom complet reste lisible au survol quand il dépasse la largeur du champ.
const libelleChoisi = computed(
    () => props.options.find((o) => o.value === valeur.value)?.label,
);

function choisir(nouvelle: string | number | null | undefined) {
    model.value =
        nouvelle === null || nouvelle === undefined || nouvelle === ''
            ? []
            : [nouvelle];
}
</script>

<template>
    <Select
        :model-value="valeur"
        :options="options"
        option-label="label"
        option-value="value"
        :placeholder="placeholder"
        :disabled="disabled"
        :title="libelleChoisi"
        filter
        auto-filter-focus
        filter-placeholder="Rechercher…"
        empty-filter-message="Aucun résultat"
        empty-message="Aucune option"
        show-clear
        fluid
        append-to="self"
        :pt="{ root: { class: 'h-9' } }"
        @update:model-value="choisir"
    />
</template>
