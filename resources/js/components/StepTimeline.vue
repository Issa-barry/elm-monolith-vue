<script setup lang="ts">
import type { Component } from 'vue';

// Frise d'avancement : mêmes pastilles, couleurs et connecteurs que la frise du détail d'une vente
// (Ventes/Show.vue). Purement visuelle — l'état de chaque étape est calculé par le serveur.
export interface TimelineStep {
    key: string;
    label: string;
    state: 'fait' | 'en_cours' | 'a_venir';
    icon: Component;
}

defineProps<{ steps: TimelineStep[] }>();
</script>

<template>
    <div class="flex items-center overflow-x-auto">
        <template v-for="(step, idx) in steps" :key="step.key">
            <div class="flex flex-col items-center" style="min-width: 80px">
                <div
                    :class="[
                        'flex h-9 w-9 items-center justify-center rounded-full transition-all',
                        step.state === 'fait'
                            ? 'bg-emerald-500 text-white shadow-sm'
                            : '',
                        step.state === 'en_cours'
                            ? 'bg-blue-600 text-white shadow-md ring-4 ring-blue-100 dark:ring-blue-900/50'
                            : '',
                        step.state === 'a_venir'
                            ? 'bg-muted text-muted-foreground'
                            : '',
                    ]"
                >
                    <component :is="step.icon" class="h-4 w-4" />
                </div>
                <span
                    :class="[
                        'mt-1.5 text-center text-[11px] leading-tight font-medium',
                        step.state === 'fait'
                            ? 'text-emerald-600 dark:text-emerald-400'
                            : '',
                        step.state === 'en_cours'
                            ? 'text-blue-600 dark:text-blue-400'
                            : '',
                        step.state === 'a_venir' ? 'text-muted-foreground' : '',
                    ]"
                >
                    {{ step.label }}
                </span>
            </div>
            <div
                v-if="idx < steps.length - 1"
                :class="[
                    'mb-5 h-0.5 flex-1 transition-all',
                    step.state === 'fait' ? 'bg-emerald-400' : 'bg-border',
                ]"
                style="min-width: 16px"
            />
        </template>
    </div>
</template>
