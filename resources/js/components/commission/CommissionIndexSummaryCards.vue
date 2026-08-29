<script setup lang="ts">
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatGNF } from '@/lib/utils';
import type { CommissionIndexSummary } from '@/types/commission';
import { Info } from 'lucide-vue-next';

interface CardLabelOverride {
    label?: string;
    ariaLabel?: string;
    tooltip?: string;
}

const props = withDefaults(
    defineProps<{
        summary: CommissionIndexSummary;
        /**
         * Surcharge label/tooltip par carte — pour les écrans où le montant retenu courant est
         * désormais toujours affiché (indépendamment de la validation de la période, cf.
         * CommissionVenteController) : « Net validé » y devient trompeur, « Net à payer » plus
         * juste. Les écrans dont le calcul sous-jacent n'a pas encore été aligné gardent le
         * libellé par défaut, pour ne pas laisser croire à un montant définitif.
         */
        labelOverrides?: Partial<
            Record<'generated' | 'expenses' | 'netValidated' | 'remaining', CardLabelOverride>
        >;
    }>(),
    {
        labelOverrides: () => ({}),
    },
);

const cardsDefaults = [
    {
        key: 'generated',
        label: 'Commissions générées',
        ariaLabel: 'Définition des commissions générées',
        tooltip:
            'Total des commissions calculées à partir des ventes ou opérations, avant validation de la période.',
    },
    {
        key: 'expenses',
        label: 'Dépenses',
        ariaLabel: 'Définition des dépenses',
        tooltip: 'Dépenses déduites des commissions déjà validées.',
    },
    {
        key: 'netValidated',
        label: 'Net validé',
        ariaLabel: 'Définition du net validé',
        tooltip:
            'Montant validé après déduction des dépenses et prise en compte des ajustements.',
    },
    {
        key: 'remaining',
        label: 'Reste à payer',
        ariaLabel: 'Définition du reste à payer',
        tooltip:
            'Net validé restant à verser après les paiements déjà effectués.',
    },
] as const;

const cards = cardsDefaults.map((card) => ({
    ...card,
    ...props.labelOverrides[card.key],
}));

function formattedValue(key: (typeof cards)[number]['key']): string {
    const value = props.summary[key];

    if (key === 'expenses' && value > 0) {
        return `-${formatGNF(value)}`;
    }

    return formatGNF(value);
}

function valueClass(key: (typeof cards)[number]['key']): string {
    if (key === 'expenses' && props.summary.expenses > 0) {
        return 'text-red-600 dark:text-red-400';
    }

    if (key === 'netValidated' && props.summary.remaining > 0) {
        return 'text-amber-600 dark:text-amber-400';
    }

    return 'text-foreground';
}

function tooltipText(key: (typeof cards)[number]['key'], text: string): string {
    if (key !== 'remaining' || props.summary.paid === undefined) {
        return text;
    }

    return `${text} Déjà payé : ${formatGNF(props.summary.paid)}.`;
}
</script>

<template>
    <TooltipProvider :delay-duration="150">
        <div
            data-testid="commission-summary-cards"
            aria-label="Synthèse des commissions"
            class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
        >
            <div
                v-for="card in cards"
                :key="card.key"
                class="rounded-xl border bg-card p-4 shadow-sm"
                :data-testid="`commission-summary-${card.key}`"
            >
                <div
                    class="flex items-center gap-1.5 text-sm text-muted-foreground"
                >
                    <span>{{ card.label }}</span>
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <button
                                type="button"
                                :aria-label="card.ariaLabel"
                                class="rounded-sm text-muted-foreground/70 transition-colors outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <Info class="h-3.5 w-3.5" />
                            </button>
                        </TooltipTrigger>
                        <TooltipContent class="max-w-xs">
                            <p>{{ tooltipText(card.key, card.tooltip) }}</p>
                        </TooltipContent>
                    </Tooltip>
                </div>
                <p
                    class="mt-1.5 text-2xl font-bold whitespace-nowrap tabular-nums"
                    :class="valueClass(card.key)"
                >
                    {{ formattedValue(card.key) }}
                </p>
            </div>
        </div>
    </TooltipProvider>
</template>
