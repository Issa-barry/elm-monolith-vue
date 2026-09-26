<script setup lang="ts">
// En-tête d'une section du rapport : titre, une phrase d'aide propre à la section et, en
// ligne, les chiffres de contexte (remplace l'ancienne deuxième rangée de cartes).
defineProps<{
    titre: string;
    aide: string;
    chiffres?: { libelle: string; valeur: string }[];
}>();
</script>

<template>
    <div class="space-y-1">
        <h2 class="text-base font-semibold">{{ titre }}</h2>
        <p class="text-xs text-muted-foreground">{{ aide }}</p>
        <p
            v-if="chiffres && chiffres.length > 0"
            class="flex flex-wrap gap-x-4 gap-y-1 pt-1 text-sm"
        >
            <span v-for="c in chiffres" :key="c.libelle">
                <span class="text-muted-foreground">{{ c.libelle }} :</span>
                <span class="ml-1 font-semibold tabular-nums">{{
                    c.valeur
                }}</span>
            </span>
        </p>
    </div>
</template>
