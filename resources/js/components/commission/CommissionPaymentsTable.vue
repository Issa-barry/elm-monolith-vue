<script setup lang="ts">
import { formatGNF } from '@/lib/utils';
import type {
    CommissionPaymentRow,
    ModePaiementOption,
} from '@/types/commission';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        rows: CommissionPaymentRow[];
        modesPaiement?: ModePaiementOption[];
        emptyMessage?: string;
    }>(),
    {
        modesPaiement: () => [],
        emptyMessage: 'Aucun paiement enregistré.',
    },
);

const total = computed(() =>
    props.rows.reduce((sum, r) => sum + r.montant, 0),
);

function formatMode(mode: string) {
    return props.modesPaiement.find((m) => m.value === mode)?.label ?? mode;
}

function shortReference(id: string) {
    return `#${id.slice(0, 8).toUpperCase()}`;
}
</script>

<template>
    <table v-if="props.rows.length > 0" class="w-full text-sm">
        <thead>
            <tr class="border-b bg-muted/40">
                <th
                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                >
                    Date
                </th>
                <th
                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                >
                    Référence
                </th>
                <th
                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                >
                    Mode de paiement
                </th>
                <th
                    class="px-4 py-3 text-right font-medium text-muted-foreground"
                >
                    Montant
                </th>
                <th
                    class="px-4 py-3 text-left font-medium text-muted-foreground"
                >
                    Saisi par
                </th>
            </tr>
        </thead>
        <tbody class="divide-y">
            <tr v-for="row in props.rows" :key="row.id" class="hover:bg-muted/10">
                <td class="px-4 py-3 text-xs text-muted-foreground">
                    {{ row.paid_at ?? '—' }}
                </td>
                <td class="px-4 py-3 font-mono text-xs text-muted-foreground">
                    {{ shortReference(row.id) }}
                </td>
                <td class="px-4 py-3 text-xs text-muted-foreground">
                    {{ formatMode(row.mode_paiement) }}
                </td>
                <td
                    class="px-4 py-3 text-right font-medium text-emerald-600 tabular-nums dark:text-emerald-400"
                >
                    {{ formatGNF(row.montant) }}
                </td>
                <td class="px-4 py-3 text-xs text-muted-foreground">
                    {{ row.created_by ?? '—' }}
                </td>
            </tr>
        </tbody>
        <tfoot>
            <tr class="border-t-2 bg-muted/50 text-xs font-bold">
                <td class="px-4 py-2.5 tracking-wide uppercase" colspan="3">
                    Total payé
                </td>
                <td
                    class="px-4 py-2.5 text-right text-emerald-600 tabular-nums dark:text-emerald-400"
                >
                    {{ formatGNF(total) }}
                </td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    <p v-else class="py-8 text-center text-sm text-muted-foreground">
        {{ props.emptyMessage }}
    </p>
</template>
