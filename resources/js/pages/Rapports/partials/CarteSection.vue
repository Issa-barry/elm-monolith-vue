<script setup lang="ts">
import { cn } from '@/lib/utils';
import { ArrowRight, ChevronDown } from 'lucide-vue-next';
import type { Component } from 'vue';

// Carte-onglet du rapport : résumé d'une section ET bouton qui en affiche le détail. La carte
// active se reconnaît sans la couleur : bordure épaisse, chevron « détail affiché » et
// aria-selected (onglet accessible).
defineProps<{
    libelle: string;
    valeur: string;
    detail: string;
    active: boolean;
    focusable?: boolean;
    testid: string;
    icone: Component;
    avertissement?: string | null;
    large?: boolean;
}>();

defineEmits<{ choisir: [] }>();
</script>

<template>
    <button
        type="button"
        role="tab"
        :id="testid"
        :aria-controls="testid.replace('rapport-tab-', 'rapport-panel-')"
        :aria-selected="active"
        :tabindex="active || focusable ? 0 : -1"
        :data-testid="testid"
        :class="
            cn(
                'group relative flex min-w-0 flex-col items-stretch gap-3 rounded-xl border p-3 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none sm:p-4',
                active
                    ? 'border-primary bg-primary/5 ring-1 ring-primary'
                    : 'border-border bg-card hover:border-primary/40 hover:bg-muted/30',
                large && 'sm:flex-row sm:items-center sm:gap-6',
            )
        "
        @click="$emit('choisir')"
    >
        <div class="min-w-0" :class="large && 'sm:flex-1'">
            <div
                class="flex items-center gap-2 text-sm font-medium"
                :class="active ? 'text-primary' : 'text-muted-foreground'"
            >
                <component
                    :is="icone"
                    class="h-4 w-4 shrink-0"
                    aria-hidden="true"
                />
                <span>{{ libelle }}</span>
            </div>
            <p
                class="mt-2 font-semibold tracking-tight break-words text-foreground tabular-nums"
                :class="large ? 'text-2xl sm:text-3xl' : 'text-base sm:text-xl'"
                data-testid="carte-valeur"
            >
                {{ valeur }}
            </p>
        </div>
        <div
            class="min-w-0 text-xs leading-relaxed text-muted-foreground"
            :class="!large && 'mt-auto'"
        >
            <span
                v-if="avertissement"
                class="font-medium text-amber-700 dark:text-amber-400"
                >⚠ {{ avertissement }}</span
            >
            <span v-else>{{ detail }}</span>
        </div>
        <div
            class="flex items-center justify-between gap-2 border-t pt-2 text-xs font-medium"
            :class="[
                active
                    ? 'border-primary/15 text-primary'
                    : 'text-muted-foreground',
                large && 'sm:border-t-0 sm:pt-0',
            ]"
            aria-hidden="true"
        >
            <span>{{ active ? 'Détail affiché' : 'Voir le détail' }}</span>
            <ChevronDown v-if="active" class="h-3.5 w-3.5 shrink-0" />
            <ArrowRight v-else class="h-3.5 w-3.5 shrink-0" />
        </div>
        <span v-if="active" class="sr-only">(détail affiché)</span>
    </button>
</template>
