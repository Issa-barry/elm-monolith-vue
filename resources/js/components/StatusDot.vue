<script setup lang="ts">
import { cn } from '@/lib/utils';
import { computed, type HTMLAttributes } from 'vue';

// Table de couleur centralisée : tout nouveau statut s'ajoute UNIQUEMENT ici.
// Le statut brut (ex: "livraison_en_cours") suffit, pas besoin de map locale.
const STATUS_COLOR_MAP: Record<string, string> = {
    // Vert — succès / terminé
    actif: 'bg-emerald-500',
    active: 'bg-emerald-500',
    valide: 'bg-emerald-500',
    validee: 'bg-emerald-500',
    approuve: 'bg-emerald-500',
    paye: 'bg-emerald-500',
    payee: 'bg-emerald-500',
    livre: 'bg-emerald-500',
    livree: 'bg-emerald-500',
    cloture: 'bg-emerald-500',
    cloturee: 'bg-emerald-500',
    receptionnee: 'bg-emerald-500',
    reception: 'bg-emerald-500',

    // Bleu — en cours
    en_cours: 'bg-blue-500',
    chargement: 'bg-blue-500',
    chargement_en_cours: 'bg-blue-500',
    livraison_en_cours: 'bg-blue-500',
    transit: 'bg-blue-500',
    calculee: 'bg-blue-500',

    // Gris — brouillon / créé / pas commencé
    brouillon: 'bg-zinc-400 dark:bg-zinc-500',
    creee: 'bg-zinc-400 dark:bg-zinc-500',
    a_charger: 'bg-zinc-400 dark:bg-zinc-500',
    inactif: 'bg-zinc-400 dark:bg-zinc-500',
    inactive: 'bg-zinc-400 dark:bg-zinc-500',

    // Rouge — impayé / rejeté / annulé
    impaye: 'bg-red-500',
    impayee: 'bg-red-500',
    a_payer: 'bg-red-500',
    rejete: 'bg-red-500',
    annule: 'bg-red-500',
    annulee: 'bg-red-500',
    ko: 'bg-red-500',

    // Orange — partiel / en attente / soumis
    partiel: 'bg-orange-500',
    partielle: 'bg-orange-500',
    partiellement_paye: 'bg-orange-500',
    en_attente: 'bg-orange-500',
    pending_validation: 'bg-orange-500',
    soumis: 'bg-orange-500',
};

const DEFAULT_DOT_CLASS = 'bg-zinc-400 dark:bg-zinc-500';

const SIZE_CLASS: Record<'sm' | 'md' | 'lg', string> = {
    sm: 'h-1.5 w-1.5',
    md: 'h-2.5 w-2.5',
    lg: 'h-3 w-3',
};

const props = withDefaults(
    defineProps<{
        label: string;
        /** Statut brut (ex: "livraison_en_cours") — résolu via STATUS_COLOR_MAP. */
        status?: string | null;
        /** Override explicite, prioritaire sur `status`. */
        dotClass?: string;
        size?: 'sm' | 'md' | 'lg';
        class?: HTMLAttributes['class'];
    }>(),
    {
        status: null,
        dotClass: undefined,
        size: 'md',
    },
);

const resolvedDotClass = computed(() => {
    if (props.dotClass) return props.dotClass;
    if (props.status) {
        return (
            STATUS_COLOR_MAP[props.status.toLowerCase()] ?? DEFAULT_DOT_CLASS
        );
    }
    return DEFAULT_DOT_CLASS;
});
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex items-center gap-2 text-xs font-medium whitespace-nowrap text-foreground',
                props.class,
            )
        "
    >
        <span
            :class="
                cn('shrink-0 rounded-full', SIZE_CLASS[size], resolvedDotClass)
            "
        />
        <span>{{ label }}</span>
    </span>
</template>
