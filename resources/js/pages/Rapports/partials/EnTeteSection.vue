<script setup lang="ts">
import InfoTooltip from '@/components/InfoTooltip.vue';

// Aide au survol et au focus, partagée avec Supports de trésorerie.
defineProps<{
    titre: string;
    aide: string;
    contexte?: string;
    chiffres?: { libelle: string; valeur: string }[];
}>();
</script>

<template>
    <div class="space-y-4">
        <div class="relative flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <h2 class="text-base font-semibold">{{ titre }}</h2>
                <InfoTooltip :label="`Comprendre : ${titre}`">{{
                    aide
                }}</InfoTooltip>
            </div>
            <p
                v-if="contexte"
                class="text-xs font-medium text-muted-foreground"
            >
                {{ contexte }}
            </p>
        </div>
        <dl
            v-if="chiffres && chiffres.length > 0"
            class="grid grid-cols-1 gap-4 rounded-lg bg-muted/40 p-3 sm:flex sm:flex-wrap sm:gap-x-8"
        >
            <div
                v-for="c in chiffres"
                :key="c.libelle"
                class="flex items-baseline justify-between gap-3 sm:block"
            >
                <dt class="text-xs text-muted-foreground">{{ c.libelle }}</dt>
                <dd class="text-sm font-semibold tabular-nums sm:mt-1">
                    {{ c.valeur }}
                </dd>
            </div>
        </dl>
    </div>
</template>
