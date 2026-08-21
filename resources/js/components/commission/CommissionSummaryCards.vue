<script setup lang="ts">
import { formatGNF } from '@/lib/utils';
import type { CommissionSummary } from '@/types/commission';

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
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
        <div class="rounded-lg border bg-card p-4 text-center">
            <p class="text-base font-bold tabular-nums">
                {{ formatGNF(props.summary.brut_cumule) }}
            </p>
            <p class="mt-1 text-xs text-muted-foreground">Brut cumulé</p>
        </div>
        <div class="rounded-lg border bg-card p-4 text-center">
            <p
                class="text-base font-bold text-red-600 tabular-nums dark:text-red-400"
            >
                {{
                    props.summary.frais > 0
                        ? '-' + formatGNF(props.summary.frais)
                        : formatGNF(0)
                }}
            </p>
            <p class="mt-1 text-xs text-muted-foreground">
                {{ props.fraisLabel }}
            </p>
        </div>
        <div class="rounded-lg border bg-card p-4 text-center">
            <p class="text-base font-bold tabular-nums">
                {{ formatGNF(props.summary.net_a_payer) }}
            </p>
            <p class="mt-1 text-xs text-muted-foreground">Net à payer</p>
        </div>
        <div class="rounded-lg border bg-card p-4 text-center">
            <p
                class="text-base font-bold text-emerald-600 tabular-nums dark:text-emerald-400"
            >
                {{ formatGNF(props.summary.deja_paye) }}
            </p>
            <p class="mt-1 text-xs text-muted-foreground">Déjà payé</p>
        </div>
        <div
            class="rounded-lg border bg-card p-4 text-center"
            :class="
                props.summary.reste_a_payer > 0
                    ? 'border-amber-200 dark:border-amber-900'
                    : ''
            "
        >
            <p
                class="text-base font-bold tabular-nums"
                :class="
                    props.summary.reste_a_payer > 0
                        ? 'text-amber-600 dark:text-amber-400'
                        : ''
                "
            >
                {{ formatGNF(props.summary.reste_a_payer) }}
            </p>
            <p class="mt-1 text-xs text-muted-foreground">Reste à payer</p>
        </div>
    </div>

    <!-- V2 uniquement : ventilation « visible ne veut pas dire payable » (cf. CommissionKpiBuckets
         côté back) — une commission déjà générée mais dont la période n'est pas encore validée
         reste comptée dans le total, jamais masquée, mais n'entre pas dans "Payable". Absent
         (null) sur les écrans Legacy/Logistique, qui n'ont pas cette notion. -->
    <div
        v-if="props.summary.total_genere !== null"
        class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3"
    >
        <div class="rounded-lg border bg-card p-4 text-center">
            <p class="text-base font-bold tabular-nums">
                {{ formatGNF(props.summary.total_genere) }}
            </p>
            <p class="mt-1 text-xs text-muted-foreground">Total généré</p>
        </div>
        <div
            class="rounded-lg border bg-card p-4 text-center"
            :class="
                (props.summary.en_attente_periode ?? 0) > 0
                    ? 'border-zinc-300 dark:border-zinc-700'
                    : ''
            "
        >
            <p
                class="text-base font-bold text-zinc-600 tabular-nums dark:text-zinc-400"
            >
                {{ formatGNF(props.summary.en_attente_periode ?? 0) }}
            </p>
            <p class="mt-1 text-xs text-muted-foreground">
                En attente de période
            </p>
        </div>
        <div
            class="rounded-lg border bg-card p-4 text-center"
            :class="
                (props.summary.payable ?? 0) > 0
                    ? 'border-amber-200 dark:border-amber-900'
                    : ''
            "
        >
            <p
                class="text-base font-bold tabular-nums"
                :class="
                    (props.summary.payable ?? 0) > 0
                        ? 'text-amber-600 dark:text-amber-400'
                        : ''
                "
            >
                {{ formatGNF(props.summary.payable ?? 0) }}
            </p>
            <p class="mt-1 text-xs text-muted-foreground">Payable</p>
        </div>
    </div>
</template>
