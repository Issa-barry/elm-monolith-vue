<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Link } from '@inertiajs/vue3';
import {
    Calculator,
    CheckCircle2,
    Circle,
    ClipboardCheck,
    HandCoins,
    Lock,
} from 'lucide-vue-next';
import { computed, type Component } from 'vue';

interface PeriodeAffichee {
    id: string;
    reference: string;
    statut: string;
    statut_label: string;
}

const props = defineProps<{
    periode: PeriodeAffichee | null;
}>();

const STEPS = ['Calcul', 'Contrôle', 'Validation', 'Paiement'] as const;

type StepState = 'done' | 'current' | 'upcoming';

interface StatutConfig {
    title: string;
    description: string;
    nextAction: string | null;
    icon: Component;
    accentClass: string;
    dotClass: string;
    /** Index (0-based) de l'étape en cours ; >= STEPS.length = tout est fait. */
    stepIndex: number;
}

// Copie métier + étapes du workflow, dérivées du statut technique déjà fourni
// par le backend (brouillon/calculee/validee/cloturee). Ce n'est PAS une
// deuxième source de couleur de statut : le point/label affiché reste celui
// de StatusDot (voir STATUS_COLOR_MAP dans StatusDot.vue) — cette table ne
// décrit que le texte d'explication, l'action suivante et la progression.
const STATUT_CONFIG: Record<string, StatutConfig> = {
    brouillon: {
        title: 'À calculer',
        description: "Cette période n'a pas encore été calculée.",
        nextAction: 'Lancer le calcul de la période.',
        icon: Calculator,
        accentClass: 'text-zinc-500 dark:text-zinc-400',
        dotClass: 'bg-zinc-400 dark:bg-zinc-500',
        stepIndex: 0,
    },
    calculee: {
        title: 'En cours de contrôle',
        description:
            'Le calcul est terminé. Vérifiez et validez les commissions avant de valider la période.',
        nextAction: 'Contrôler les commissions puis valider la période.',
        icon: ClipboardCheck,
        accentClass: 'text-blue-600 dark:text-blue-400',
        dotClass: 'bg-blue-500',
        stepIndex: 1,
    },
    validee: {
        title: 'Prête au paiement',
        description: 'La période est validée. Les paiements peuvent être effectués.',
        nextAction: 'Effectuer les paiements restants.',
        icon: HandCoins,
        accentClass: 'text-emerald-600 dark:text-emerald-400',
        dotClass: 'bg-emerald-500',
        stepIndex: 3,
    },
    cloturee: {
        title: 'Clôturée',
        description: 'Cette période est clôturée et figée.',
        nextAction: null,
        icon: Lock,
        accentClass: 'text-emerald-600 dark:text-emerald-400',
        dotClass: 'bg-emerald-500',
        stepIndex: 4,
    },
};

const DEFAULT_CONFIG: Omit<StatutConfig, 'title'> = {
    description: '',
    nextAction: null,
    icon: Circle,
    accentClass: 'text-muted-foreground',
    dotClass: 'bg-zinc-400 dark:bg-zinc-500',
    stepIndex: 0,
};

const config = computed<StatutConfig>(() => {
    if (!props.periode) return { ...DEFAULT_CONFIG, title: '' };
    const known = STATUT_CONFIG[props.periode.statut.toLowerCase()];
    if (known) return known;
    return { ...DEFAULT_CONFIG, title: props.periode.statut_label };
});

function stepState(index: number): StepState {
    const current = config.value.stepIndex;
    if (current >= STEPS.length) return 'done';
    if (index < current) return 'done';
    if (index === current) return 'current';
    return 'upcoming';
}
</script>

<template>
    <div v-if="periode" class="rounded-xl border bg-card p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-start gap-3">
                <component
                    :is="config.icon"
                    :class="['mt-0.5 h-5 w-5 shrink-0', config.accentClass]"
                />
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-semibold text-foreground">
                            {{ config.title }}
                        </p>
                        <Link
                            :href="`/backoffice/comptabilite/periodes/${periode.id}`"
                            class="font-mono text-xs text-muted-foreground hover:underline"
                        >
                            {{ periode.reference }}
                        </Link>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ config.description }}
                    </p>
                    <p
                        v-if="config.nextAction"
                        class="mt-1.5 text-sm font-medium text-foreground"
                    >
                        → {{ config.nextAction }}
                    </p>
                </div>
            </div>
            <StatusDot
                :status="periode.statut"
                :label="periode.statut_label"
                class="shrink-0"
            />
        </div>

        <div class="mt-4 flex items-center gap-1.5 overflow-x-auto pb-0.5">
            <template v-for="(step, index) in STEPS" :key="step">
                <div class="flex shrink-0 items-center gap-1.5">
                    <CheckCircle2
                        v-if="stepState(index) === 'done'"
                        class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400"
                    />
                    <span
                        v-else-if="stepState(index) === 'current'"
                        :class="[
                            'h-2 w-2 shrink-0 rounded-full',
                            config.dotClass,
                        ]"
                    />
                    <Circle
                        v-else
                        class="h-4 w-4 shrink-0 text-muted-foreground/40"
                    />
                    <span
                        :class="[
                            'text-xs whitespace-nowrap',
                            stepState(index) === 'upcoming'
                                ? 'text-muted-foreground/60'
                                : 'text-foreground',
                            stepState(index) === 'current' && 'font-medium',
                        ]"
                    >
                        {{ step }}
                    </span>
                </div>
                <div
                    v-if="index < STEPS.length - 1"
                    class="h-px w-6 shrink-0 bg-border sm:w-10"
                />
            </template>
        </div>
    </div>
</template>
