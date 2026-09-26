<script setup lang="ts">
import { cn } from '@/lib/utils';
import { ChevronDown } from 'lucide-vue-next';

// Carte-onglet du rapport : résumé d'une section ET bouton qui en affiche le détail. La carte
// active se reconnaît sans la couleur : bordure épaisse, chevron « détail affiché » et
// aria-selected (onglet accessible).
defineProps<{
    libelle: string;
    valeur: string;
    detail: string;
    active: boolean;
    testid: string;
    avertissement?: string | null;
    large?: boolean;
}>();

defineEmits<{ choisir: [] }>();
</script>

<template>
    <button
        type="button"
        role="tab"
        :aria-selected="active"
        :data-testid="testid"
        :class="
            cn(
                'relative flex min-w-0 flex-col items-start rounded-xl bg-card px-3 py-2.5 text-left transition-colors hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                active
                    ? 'border-2 border-primary shadow-sm'
                    : 'border border-border',
                large && 'sm:flex-row sm:items-center sm:justify-between',
            )
        "
        @click="$emit('choisir')"
    >
        <div class="min-w-0">
            <p
                class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
            >
                {{ libelle }}
            </p>
            <p
                class="mt-0.5 truncate font-bold tabular-nums"
                :class="large ? 'text-2xl' : 'text-base sm:text-lg'"
                data-testid="carte-valeur"
            >
                {{ valeur }}
            </p>
        </div>
        <div class="mt-0.5 min-w-0 text-xs text-muted-foreground">
            <span
                v-if="avertissement"
                class="font-medium text-amber-700 dark:text-amber-400"
                >⚠ {{ avertissement }}</span
            >
            <span v-else>{{ detail }}</span>
        </div>
        <ChevronDown
            v-if="active"
            aria-hidden="true"
            class="absolute top-2 right-2 h-3.5 w-3.5 text-primary"
        />
        <span v-if="active" class="sr-only">(détail affiché)</span>
    </button>
</template>
