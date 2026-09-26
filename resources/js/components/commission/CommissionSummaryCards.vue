<script setup lang="ts">
import { formatGNF } from '@/lib/utils';
import type { CommissionSummary } from '@/types/commission';
import {
    BadgeCheck,
    CircleDollarSign,
    ReceiptText,
    WalletCards,
} from 'lucide-vue-next';

const props = withDefaults(
    defineProps<{
        summary: CommissionSummary;
        fraisLabel?: string;
    }>(),
    {
        fraisLabel: 'Dépenses',
    },
);
</script>

<template>
    <div
        data-testid="commission-detail-summary"
        class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4"
    >
        <div class="rounded-xl border bg-card p-4 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <p class="text-xs font-medium text-muted-foreground">
                    {{
                        props.summary.total_genere !== null
                            ? 'Commissions générées'
                            : 'Brut cumulé'
                    }}
                </p>
                <span
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"
                >
                    <CircleDollarSign class="h-4 w-4" />
                </span>
            </div>
            <p class="mt-3 text-xl font-semibold tracking-tight tabular-nums">
                {{
                    formatGNF(
                        props.summary.total_genere ?? props.summary.brut_cumule,
                    )
                }}
            </p>
        </div>

        <div class="rounded-xl border bg-card p-4 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <p class="text-xs font-medium text-muted-foreground">
                    {{ props.fraisLabel }}
                </p>
                <span
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-red-500/10 text-red-600 dark:text-red-400"
                >
                    <ReceiptText class="h-4 w-4" />
                </span>
            </div>
            <p
                class="mt-3 text-xl font-semibold tracking-tight tabular-nums"
                :class="
                    props.summary.frais > 0
                        ? 'text-red-600 dark:text-red-400'
                        : 'text-foreground'
                "
            >
                {{
                    props.summary.frais > 0
                        ? '-' + formatGNF(props.summary.frais)
                        : formatGNF(0)
                }}
            </p>
        </div>

        <div class="rounded-xl border bg-card p-4 shadow-sm">
            <div class="flex items-start justify-between gap-3">
                <p class="text-xs font-medium text-muted-foreground">
                    {{
                        props.summary.total_genere !== null
                            ? 'Net validé'
                            : 'Net à payer'
                    }}
                </p>
                <span
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
                >
                    <BadgeCheck class="h-4 w-4" />
                </span>
            </div>
            <p class="mt-3 text-xl font-semibold tracking-tight tabular-nums">
                {{ formatGNF(props.summary.net_a_payer) }}
            </p>
        </div>

        <div
            class="rounded-xl border bg-card p-4 shadow-sm"
            :class="
                props.summary.reste_a_payer > 0
                    ? 'border-amber-300/70 bg-amber-500/5 dark:border-amber-900'
                    : ''
            "
        >
            <div class="flex items-start justify-between gap-3">
                <p class="text-xs font-medium text-muted-foreground">
                    Reste à payer
                </p>
                <span
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400"
                >
                    <WalletCards class="h-4 w-4" />
                </span>
            </div>
            <p
                class="mt-3 text-xl font-semibold tracking-tight tabular-nums"
                :class="
                    props.summary.reste_a_payer > 0
                        ? 'text-amber-600 dark:text-amber-400'
                        : ''
                "
            >
                {{ formatGNF(props.summary.reste_a_payer) }}
            </p>
            <p class="mt-1.5 text-xs text-muted-foreground tabular-nums">
                <span>Déjà payé</span>
                <span> : {{ formatGNF(props.summary.deja_paye) }}</span>
            </p>
        </div>
    </div>
</template>
