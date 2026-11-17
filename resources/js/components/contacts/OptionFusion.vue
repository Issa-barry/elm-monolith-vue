<script setup lang="ts">
/**
 * Option d'un choix de fusion (valeur d'un champ, numéro, interlocuteur) : carte cliquable qui se
 * comporte comme un bouton radio. La valeur vide s'affiche « Non renseigné ».
 */
import { Check } from 'lucide-vue-next';

defineProps<{
    selectionne: boolean;
    source: string;
    valeur: string | null;
    desactive?: boolean;
}>();
defineEmits<{ choisir: [] }>();
</script>

<template>
    <button
        type="button"
        role="radio"
        :aria-checked="selectionne"
        :disabled="desactive"
        class="flex w-full items-start gap-3 rounded-lg border px-3 py-2 text-left text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-60"
        :class="
            selectionne
                ? 'border-primary bg-primary/5 ring-1 ring-primary'
                : 'border-border hover:bg-muted/50'
        "
        @click="$emit('choisir')"
    >
        <span
            class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border"
            :class="
                selectionne
                    ? 'border-primary bg-primary text-primary-foreground'
                    : 'border-muted-foreground/40'
            "
        >
            <Check v-if="selectionne" class="h-3 w-3" />
        </span>
        <span class="min-w-0">
            <span v-if="source" class="block text-xs text-muted-foreground">{{
                source
            }}</span>
            <span
                class="block break-words"
                :class="
                    valeur === null
                        ? 'text-muted-foreground italic'
                        : 'font-medium'
                "
            >
                {{ valeur ?? 'Non renseigné' }}
            </span>
        </span>
    </button>
</template>
